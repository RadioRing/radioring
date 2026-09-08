<?php

namespace App\Console\Commands;

use App\Jobs\CreateBackupJob;
use App\Models\Backup;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backup:run
    {--auto : Nightly run: uses the stored passphrase and is marked as automatic}
    {--passphrase= : Encrypt the archive with this passphrase}
    {--queue : Hand the run to the queue instead of doing it here}')]
#[Description('Creates a configuration backup (database, .env, APP_KEY) and applies the retention limit')]
class RunBackup extends Command
{
    public function handle(BackupService $backups): int
    {
        $automatic = (bool) $this->option('auto');

        $passphrase = $automatic
            ? BackupSettings::passphrase()
            : ($this->option('passphrase') ?: null);

        $backup = Backup::create([
            'kind' => BackupService::KIND_CONFIG,
            'status' => Backup::STATUS_PENDING,
            'automatic' => $automatic,
        ]);

        if ($this->option('queue')) {
            CreateBackupJob::dispatch($backup->id, $passphrase);

            $this->info(__('Backup queued.'));

            return self::SUCCESS;
        }

        $backup = $backups->run($backup, $passphrase);

        if ($backup->status !== Backup::STATUS_COMPLETED) {
            $this->error(__('Backup failed: :error', ['error' => $backup->error]));

            return self::FAILURE;
        }

        $this->info(__('Backup written: :file (:size)', [
            'file' => $backup->filename,
            'size' => $backup->humanSize(),
        ]));

        if (! $backup->encrypted) {
            $this->warn(__('The archive is unencrypted and contains APP_KEY. Keep it somewhere safe.'));
        }

        return self::SUCCESS;
    }
}
