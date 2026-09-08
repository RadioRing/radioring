<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a recorded backup in the background, so the panel does not wait for it.
 *
 * Encrypted on the queue: the payload carries the archive passphrase, which would
 * otherwise sit in the jobs table in plain text until the worker picks it up.
 */
class CreateBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * A failed backup is recorded as failed by the service itself. Retrying would only
     * produce a second archive of the same broken state.
     */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $backupId,
        public readonly ?string $passphrase = null,
    ) {}

    public function handle(BackupService $backups): void
    {
        $backup = Backup::find($this->backupId);

        if (! $backup || ! $backup->isRunning()) {
            return;
        }

        $backups->run($backup, $this->passphrase);
    }

    public function failed(?\Throwable $exception): void
    {
        Backup::where('id', $this->backupId)
            ->where('status', '!=', Backup::STATUS_COMPLETED)
            ->update([
                'status' => Backup::STATUS_FAILED,
                'error' => $exception?->getMessage(),
                'finished_at' => now(),
            ]);
    }
}
