<?php

namespace App\Livewire\Admin;

use App\Jobs\CreateBackupJob;
use App\Models\Backup;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Backups')]
class Backups extends Component
{
    /**
     * Passphrase for the next manual backup. Empty means an unencrypted archive.
     */
    #[Validate('nullable|string|min:8|max:200')]
    public string $passphrase = '';

    public bool $autoEnabled = false;

    #[Validate('required|date_format:H:i')]
    public string $autoTime = BackupSettings::DEFAULT_TIME;

    #[Validate('required|integer|min:1|max:365')]
    public int $retention = BackupSettings::DEFAULT_RETENTION;

    /**
     * New passphrase for automatic backups. Empty leaves the stored one untouched.
     */
    #[Validate('nullable|string|min:8|max:200')]
    public string $autoPassphrase = '';

    public function mount(): void
    {
        $this->autoEnabled = BackupSettings::autoEnabled();
        $this->autoTime = BackupSettings::autoTime();
        $this->retention = BackupSettings::retention();
    }

    #[Computed]
    public function backups()
    {
        return Backup::with('creator')->latest('id')->take(50)->get();
    }

    #[Computed]
    public function hasRunningBackup(): bool
    {
        return Backup::query()
            ->whereIn('status', [Backup::STATUS_PENDING, Backup::STATUS_RUNNING])
            ->exists();
    }

    #[Computed]
    public function storedPassphraseIsSet(): bool
    {
        return BackupSettings::hasPassphrase();
    }

    #[Computed]
    public function totalSize(): string
    {
        $backup = new Backup(['size_bytes' => app(BackupService::class)->totalSizeBytes()]);

        return $backup->humanSize();
    }

    #[Computed]
    public function freeSpace(): ?string
    {
        $free = app(BackupService::class)->freeSpaceBytes();

        return $free === null ? null : (new Backup(['size_bytes' => $free]))->humanSize();
    }

    public function createBackup(): void
    {
        $this->validateOnly('passphrase');

        if ($this->hasRunningBackup()) {
            $this->dispatch('notify', message: __('A backup is already running.'), type: 'warning');

            return;
        }

        $backup = Backup::create([
            'kind' => BackupService::KIND_CONFIG,
            'status' => Backup::STATUS_PENDING,
            'automatic' => false,
            'created_by' => auth()->id(),
        ]);

        CreateBackupJob::dispatch($backup->id, $this->passphrase !== '' ? $this->passphrase : null);

        $this->reset('passphrase');
        unset($this->backups, $this->hasRunningBackup);

        $this->dispatch('notify', message: __('Backup started. It appears in the list when it is done.'), type: 'success');
    }

    public function deleteBackup(int $backupId): void
    {
        $backup = Backup::findOrFail($backupId);

        app(BackupService::class)->delete($backup);

        unset($this->backups, $this->totalSize);

        $this->dispatch('notify', message: __('Backup deleted.'), type: 'success');
    }

    public function saveSettings(): void
    {
        $this->validateOnly('autoTime');
        $this->validateOnly('retention');
        $this->validateOnly('autoPassphrase');

        BackupSettings::setAutoEnabled($this->autoEnabled);
        BackupSettings::setAutoTime($this->autoTime);
        BackupSettings::setRetention($this->retention);

        if ($this->autoPassphrase !== '') {
            BackupSettings::setPassphrase($this->autoPassphrase);
            $this->reset('autoPassphrase');
        }

        // Retention applies right away, otherwise a lowered limit would only take effect
        // after the next run and the operator would think it did nothing.
        app(BackupService::class)->prune($this->retention);

        unset($this->backups, $this->storedPassphraseIsSet, $this->totalSize);

        $this->dispatch('notify', message: __('Backup settings saved.'), type: 'success');
    }

    public function clearPassphrase(): void
    {
        BackupSettings::setPassphrase(null);
        $this->reset('autoPassphrase');

        unset($this->storedPassphraseIsSet);

        $this->dispatch('notify', message: __('Automatic backups are no longer encrypted.'), type: 'success');
    }

    public function render()
    {
        return view('livewire.admin.backups')->layout('layouts.app');
    }
}
