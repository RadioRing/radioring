<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\Setting;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

#[Signature('backup:restore
    {backup : Backup ID, file name in the backup directory, or a path to an archive}
    {--passphrase= : Passphrase of an encrypted archive}
    {--force : Restore without asking, and even if APP_KEY differs}')]
#[Description('Restores the database from a configuration backup. Replaces all existing data.')]
class RestoreBackup extends Command
{
    public function handle(BackupCipher $cipher, DatabaseDumper $dumper): int
    {
        $archive = $this->locateArchive();

        if ($archive === null) {
            $this->error(__('No backup found for ":input".', ['input' => $this->argument('backup')]));

            return self::FAILURE;
        }

        $workDirectory = storage_path('app/private/'.Backup::DIRECTORY.'/restore-'.Str::random(12));
        File::ensureDirectoryExists($workDirectory);

        try {
            $plainArchive = $this->decryptIfNeeded($cipher, $archive, $workDirectory);
            $manifest = $this->extract($plainArchive, $workDirectory);

            $this->summarise($manifest, $archive);

            if (! $this->checkAppKey($manifest)) {
                return self::FAILURE;
            }

            if (! $this->option('force') && ! $this->confirm(__('Replace all data of this installation with the backup?'), false)) {
                $this->line(__('Cancelled, nothing changed.'));

                return self::SUCCESS;
            }

            $dumper->restore($workDirectory.DIRECTORY_SEPARATOR.BackupService::databaseFile());
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($workDirectory);
        }

        Setting::flushMemo();
        $this->call('cache:clear');

        $this->info(__('Database restored.'));
        $this->line(__('Restart the station containers so they pick up the restored configuration.'));

        return self::SUCCESS;
    }

    /**
     * Accepts a backup ID, a file name inside the backup directory, or any path.
     */
    private function locateArchive(): ?string
    {
        $input = (string) $this->argument('backup');

        if (ctype_digit($input)) {
            $backup = Backup::find((int) $input);
            $path = $backup?->path();

            return $path !== null && Storage::disk('local')->exists($path)
                ? Storage::disk('local')->path($path)
                : null;
        }

        if (File::isFile($input)) {
            return $input;
        }

        $inDirectory = Storage::disk('local')->path(Backup::DIRECTORY.'/'.$input);

        return File::isFile($inDirectory) ? $inDirectory : null;
    }

    private function decryptIfNeeded(BackupCipher $cipher, string $archive, string $workDirectory): string
    {
        if (! $cipher->isEncrypted($archive)) {
            return $archive;
        }

        $passphrase = $this->option('passphrase') ?: $this->secret(__('Passphrase of this archive'));

        if (! $passphrase) {
            throw new RuntimeException(__('This archive is encrypted. Without the passphrase it cannot be restored.'));
        }

        $target = $workDirectory.DIRECTORY_SEPARATOR.'archive.zip';
        $cipher->decryptFile($archive, $target, $passphrase);

        return $target;
    }

    /**
     * Unpacks manifest and database dump, and returns the manifest.
     *
     * @return array<string, mixed>
     */
    private function extract(string $archive, string $workDirectory): array
    {
        $zip = new ZipArchive;

        if ($zip->open($archive) !== true) {
            throw new RuntimeException(__('The archive cannot be opened. Wrong passphrase, or the file is damaged.'));
        }

        $manifestJson = $zip->getFromName(BackupService::manifestFile());

        if ($manifestJson === false) {
            $zip->close();

            throw new RuntimeException(__('The archive holds no manifest and is not a RadioRing backup.'));
        }

        // Only the two files RadioRing wrote are unpacked, never the whole archive: an
        // extractTo() would follow whatever path names a manipulated archive carries.
        if (! $zip->extractTo($workDirectory, [BackupService::databaseFile()])) {
            $zip->close();

            throw new RuntimeException(__('The database dump could not be unpacked.'));
        }

        $zip->close();

        return json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function summarise(array $manifest, string $archive): void
    {
        $this->line(__('Archive: :file', ['file' => $archive]));
        $this->line(__('Created: :date', ['date' => $manifest['created_at'] ?? '?']));
        $this->line(__('Version: :version', ['version' => $manifest['app_version'] ?: ($manifest['app_commit'] ?: '?')]));
        $this->line(__('Database: :driver', ['driver' => $manifest['database_driver'] ?? '?']));
        $this->newLine();
    }

    /**
     * Encrypted columns (station tokens, stream credentials) are unreadable under a
     * different APP_KEY. Restoring anyway produces an instance that looks intact and
     * fails at the first container start, so it takes an explicit --force.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function checkAppKey(array $manifest): bool
    {
        if (($manifest['app_key'] ?? null) === config('app.key')) {
            return true;
        }

        $this->warn(__('The APP_KEY of this installation differs from the one in the backup.'));
        $this->line(__('Encrypted values (station tokens, stream credentials) will be unreadable.'));
        $this->line(__('The key of the backup is in its manifest.json, and in the "env" file of the archive.'));

        if (! $this->option('force')) {
            $this->error(__('Set APP_KEY to that value first, or repeat with --force.'));

            return false;
        }

        return true;
    }
}
