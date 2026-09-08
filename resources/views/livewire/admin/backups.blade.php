<div @if($this->hasRunningBackup) wire:poll.3s @endif>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-shield-check me-2 text-primary"></i>{{ __('Backups') }}
        </h4>
        <a href="{{ route('admin.settings') }}" class="btn btn-outline-secondary btn-sm" wire:navigate>
            <i class="bi bi-sliders me-1"></i>{{ __('Instance settings') }}
        </a>
    </div>

    <div class="alert alert-warning d-flex gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <p class="fw-medium mb-1">{{ __('A backup contains the database and APP_KEY.') }}</p>
            <p class="mb-0 text-muted-sm">
                {{ __('That is everything needed to read station tokens and stream credentials. Give it a passphrase before it leaves this server.') }}
                {{ __('Media files are not included.') }}
            </p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-medium">
                    <i class="bi bi-play-circle me-1"></i>{{ __('Create backup now') }}
                </div>
                <div class="card-body">
                    <label class="form-label small fw-medium mb-1">{{ __('Passphrase (optional)') }}</label>
                    <input type="password" wire:model="passphrase" autocomplete="new-password"
                           class="form-control form-control-sm @error('passphrase') is-invalid @enderror"
                           placeholder="{{ __('Leave empty for an unencrypted archive') }}">
                    @error('passphrase') <div class="invalid-feedback">{{ $message }}</div> @enderror

                    <p class="text-muted-sm mt-2 mb-3">
                        {{ __('A lost passphrase cannot be recovered. The archive is then worthless.') }}
                    </p>

                    <button class="btn btn-primary btn-sm" wire:click="createBackup"
                            wire:loading.attr="disabled" @disabled($this->hasRunningBackup)>
                        <i class="bi bi-box-arrow-down me-1"></i>{{ __('Start backup') }}
                    </button>

                    @if($this->hasRunningBackup)
                        <span class="text-muted-sm ms-2">
                            <span class="spinner-border spinner-border-sm me-1"></span>{{ __('A backup is running...') }}
                        </span>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-medium">
                    <i class="bi bi-clock-history me-1"></i>{{ __('Automatic backups') }}
                </div>
                <div class="card-body">
                    <form wire:submit="saveSettings">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="auto-enabled" wire:model.live="autoEnabled">
                            <label class="form-check-label" for="auto-enabled">
                                {{ __('Run a backup every night') }}
                            </label>
                        </div>

                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label small fw-medium mb-1">{{ __('Time') }}</label>
                                <input type="time" wire:model="autoTime"
                                       class="form-control form-control-sm @error('autoTime') is-invalid @enderror"
                                       @disabled(! $autoEnabled)>
                                @error('autoTime') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-medium mb-1">{{ __('Keep this many backups') }}</label>
                                <input type="number" min="1" max="365" wire:model="retention"
                                       class="form-control form-control-sm @error('retention') is-invalid @enderror">
                                @error('retention') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <label class="form-label small fw-medium mb-1 mt-3">{{ __('Passphrase for automatic backups') }}</label>
                        <div class="input-group input-group-sm">
                            <input type="password" wire:model="autoPassphrase" autocomplete="new-password"
                                   class="form-control @error('autoPassphrase') is-invalid @enderror"
                                   placeholder="{{ $this->storedPassphraseIsSet ? __('Stored, leave empty to keep it') : __('None set') }}">
                            @if($this->storedPassphraseIsSet)
                                <button type="button" class="btn btn-outline-secondary" wire:click="clearPassphrase">
                                    {{ __('Remove') }}
                                </button>
                            @endif
                            @error('autoPassphrase') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <p class="text-muted-sm mt-2 mb-3">
                            {{ __('Oldest backups are deleted once the limit is reached. Failed runs stay in the list.') }}
                        </p>

                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-lg me-1"></i>{{ __('Save') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-medium"><i class="bi bi-archive me-1"></i>{{ __('Stored backups') }}</span>
            <span class="text-muted-sm">
                {{ __('Total: :size', ['size' => $this->totalSize]) }}
                @if($this->freeSpace)
                    · {{ __('Free on disk: :size', ['size' => $this->freeSpace]) }}
                @endif
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Created') }}</th>
                        <th>{{ __('Origin') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-end">{{ __('Size') }}</th>
                        <th class="text-end">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->backups as $backup)
                        <tr wire:key="backup-{{ $backup->id }}">
                            <td>
                                {{ $backup->created_at->format('d.m.Y H:i') }}
                                @if($backup->filename)
                                    <span class="d-block text-muted" style="font-size:.7rem">{{ $backup->filename }}</span>
                                @endif
                            </td>
                            <td class="text-muted-sm">
                                @if($backup->automatic)
                                    <i class="bi bi-clock me-1"></i>{{ __('Automatic') }}
                                @else
                                    <i class="bi bi-person me-1"></i>{{ $backup->creator?->email ?? __('Manual') }}
                                @endif
                            </td>
                            <td>
                                @if($backup->status === \App\Models\Backup::STATUS_COMPLETED)
                                    <span class="badge text-bg-success">{{ __('Done') }}</span>
                                    @if($backup->encrypted)
                                        <span class="badge text-bg-secondary" title="{{ __('Encrypted with a passphrase') }}">
                                            <i class="bi bi-lock"></i>
                                        </span>
                                    @endif
                                @elseif($backup->status === \App\Models\Backup::STATUS_FAILED)
                                    <span class="badge text-bg-danger">{{ __('Failed') }}</span>
                                    <span class="d-block text-danger" style="font-size:.7rem">{{ $backup->error }}</span>
                                @else
                                    <span class="badge text-bg-info">{{ __('Running') }}</span>
                                @endif
                            </td>
                            <td class="text-end text-muted-sm">{{ $backup->humanSize() }}</td>
                            <td class="text-end">
                                @if($backup->isDownloadable())
                                    <a href="{{ route('admin.backups.download', $backup) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-download"></i>
                                    </a>
                                @endif
                                <button class="btn btn-sm btn-outline-danger"
                                        @click="$dispatch('confirm-dialog', { message: @js(__('Delete this backup?')), confirmText: @js(__('Delete')), onConfirm: () => $wire.deleteBackup({{ $backup->id }}) })">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">{{ __('No backups yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            <h6 class="fw-semibold"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Restoring') }}</h6>
            <p class="text-muted-sm mb-2">
                {{ __('A restore replaces all data of this installation and therefore only runs on the command line, on the host:') }}
            </p>
            <pre class="bg-body-secondary p-2 rounded small mb-2"><code>php artisan backup:restore &lt;{{ __('file') }}&gt; --passphrase=...</code></pre>
            <p class="text-muted-sm mb-0">
                {{ __('The archive holds the APP_KEY it was written with. Without that key the encrypted values in the backup stay unreadable.') }}
            </p>
        </div>
    </div>
</div>
