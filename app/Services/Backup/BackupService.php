<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds and prunes configuration backups.
 *
 * A configuration backup holds everything that is not audio: the database, the `.env` if
 * the installation has one, and a manifest that carries APP_KEY. Without that key the
 * encrypted columns in the dump (station tokens, stream credentials) are unreadable, so a
 * backup without it would restore into a broken instance. It also makes the archive a
 * complete set of keys to the installation, which is why it can be encrypted with a
 * passphrase and never leaves the private disk unprotected.
 *
 * Media files are deliberately not part of this: they are orders of magnitude larger and
 * belong in their own, separately scheduled kind of backup.
 */
class BackupService
{
    public const MANIFEST_VERSION = 1;

    public const KIND_CONFIG = 'config';

    private const MANIFEST_FILE = 'manifest.json';

    private const DATABASE_FILE = 'database.jsonl';

    private const ENV_FILE = 'env';

    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly BackupCipher $cipher,
    ) {}

    /**
     * Runs a backup that has already been recorded, and marks the record either way.
     *
     * Failures are caught on purpose: a failed run must stay visible in the overview, and
     * a nightly job that throws would only end up in the log nobody reads.
     */
    public function run(Backup $backup, ?string $passphrase = null): Backup
    {
        $backup->update([
            'status' => Backup::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $disk = Storage::disk('local');
        $workDirectory = $disk->path(Backup::DIRECTORY.'/tmp-'.Str::random(12));

        try {
            File::ensureDirectoryExists($workDirectory);
            File::ensureDirectoryExists($disk->path(Backup::DIRECTORY));

            $filename = $this->buildFilename($passphrase !== null);
            $archive = $workDirectory.DIRECTORY_SEPARATOR.'archive.zip';

            $this->writeArchive($workDirectory, $archive);

            $target = $disk->path(Backup::DIRECTORY.'/'.$filename);

            if ($passphrase !== null) {
                $this->cipher->encryptFile($archive, $target, $passphrase);
            } else {
                File::move($archive, $target);
            }

            $backup->update([
                'status' => Backup::STATUS_COMPLETED,
                'filename' => $filename,
                'size_bytes' => filesize($target) ?: 0,
                'encrypted' => $passphrase !== null,
                'finished_at' => now(),
                'error' => null,
            ]);

            $this->prune();
        } catch (Throwable $exception) {
            $backup->update([
                'status' => Backup::STATUS_FAILED,
                'error' => Str::limit($exception->getMessage(), 500),
                'finished_at' => now(),
            ]);
        } finally {
            File::deleteDirectory($workDirectory);
        }

        return $backup->refresh();
    }

    /**
     * Deletes finished backups beyond the retention limit, oldest first.
     *
     * Failed runs are not counted and not deleted here: they carry no file, and keeping
     * them is the only way a broken nightly backup becomes visible.
     *
     * @return int number of deleted backups
     */
    public function prune(?int $keep = null): int
    {
        $keep = $keep ?? BackupSettings::retention();

        $obsolete = Backup::query()
            ->where('status', Backup::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($obsolete as $backup) {
            $this->delete($backup);
        }

        return $obsolete->count();
    }

    /**
     * Removes a backup with its archive.
     */
    public function delete(Backup $backup): void
    {
        $path = $backup->path();

        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }

        $backup->delete();
    }

    /**
     * Bytes currently occupied by all stored archives.
     */
    public function totalSizeBytes(): int
    {
        return (int) Backup::query()->where('status', Backup::STATUS_COMPLETED)->sum('size_bytes');
    }

    /**
     * Free space on the volume the archives live on, or null when it cannot be read.
     */
    public function freeSpaceBytes(): ?int
    {
        $free = @disk_free_space(Storage::disk('local')->path(Backup::DIRECTORY));

        return $free === false ? null : (int) $free;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'format' => self::MANIFEST_VERSION,
            'kind' => self::KIND_CONFIG,
            'created_at' => now()->toIso8601String(),
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
            'app_key' => config('app.key'),
            'app_version' => config('radioring.version.name'),
            'app_commit' => config('radioring.version.commit'),
            'database_driver' => $this->dumper->driver(),
            'php_version' => PHP_VERSION,
        ];
    }

    private function writeArchive(string $workDirectory, string $archivePath): void
    {
        $databasePath = $workDirectory.DIRECTORY_SEPARATOR.self::DATABASE_FILE;
        $this->dumper->dump($databasePath);

        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create the archive at {$archivePath}.");
        }

        $zip->addFromString(
            self::MANIFEST_FILE,
            json_encode($this->manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        $zip->addFile($databasePath, self::DATABASE_FILE);

        // Installations that run from a .env (bare metal, local development) keep it in
        // the archive. A container installation has none: its configuration comes from
        // the environment and belongs to the compose file, not to RadioRing.
        $env = base_path('.env');

        if (File::exists($env)) {
            $zip->addFile($env, self::ENV_FILE);
        }

        $zip->close();
    }

    private function buildFilename(bool $encrypted): string
    {
        $name = 'radioring-'.self::KIND_CONFIG.'-'.now()->format('Y-m-d_His').'-'.Str::lower(Str::random(4)).'.zip';

        return $encrypted ? $name.'.enc' : $name;
    }

    public static function manifestFile(): string
    {
        return self::MANIFEST_FILE;
    }

    public static function databaseFile(): string
    {
        return self::DATABASE_FILE;
    }
}
