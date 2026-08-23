<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-music-note-list me-2 text-primary"></i>{{ __('Playlisten') }}
        </h4>
        <button class="btn btn-primary btn-sm" wire:click="$set('showCreateForm', true)">
            <i class="bi bi-plus-lg me-1"></i>{{ __('Neue Playlist') }}
        </button>
    </div>

    {{-- Erstellen-Formular --}}
    @if($showCreateForm)
        <div class="card mb-4" style="max-width: 520px;">
            <div class="card-body">
                <h6 class="fw-semibold mb-3">{{ __('Neue Playlist') }}</h6>
                <form wire:submit="create">
                    <div class="mb-3">
                        <label class="form-label">{{ __('Name') }}</label>
                        <input type="text" wire:model="newName"
                               class="form-control @error('newName') is-invalid @enderror"
                               placeholder="{{ __('z.B. Morning Show') }}" autofocus>
                        @error('newName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Wiedergabemodus') }}</label>
                        <select wire:model="newPlaybackMode" class="form-select">
                            <option value="sequential">{{ __('Sequentiell') }}</option>
                            <option value="random">{{ __('Zufällig') }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Start zur vollen Stunde') }}</label>
                        <select wire:model="newStartMode" class="form-select">
                            <option value="soft">{{ __('Weich – Überhang darf auslaufen') }}</option>
                            <option value="hard">{{ __('Hart – schneidet zur vollen Stunde') }}</option>
                        </select>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">{{ __('Erstellen') }}</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                wire:click="$set('showCreateForm', false)">{{ __('Abbrechen') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Liste --}}
    @if($playlists->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-music-note-beamed display-4 text-muted"></i>
            <p class="mt-3 text-muted">{{ __('Noch keine Playlisten vorhanden.') }}</p>
        </div>
    @else
        <div class="list-group">
            @foreach($playlists as $playlist)
                <div class="list-group-item list-group-item-action d-flex align-items-center justify-content-between py-3">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-collection-play fs-5 text-primary"></i>
                        <div>
                            <div class="fw-medium">{{ $playlist->name }}</div>
                            <div class="text-muted-sm d-flex align-items-center gap-2 mt-1">
                                <span>
                                    <i class="bi bi-{{ $playlist->playback_mode === 'random' ? 'shuffle' : 'list-ol' }} me-1"></i>
                                    {{ $playlist->playback_mode === 'random' ? __('Zufällig') : __('Sequentiell') }}
                                </span>
                                <span>·</span>
                                <span>{{ $playlist->items_count }} {{ __('Tracks') }}</span>
                                @if($playlist->start_mode === 'hard')
                                    <span>·</span>
                                    <span class="text-danger"><i class="bi bi-clock-fill me-1"></i>{{ __('Harter Start') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('playlist.manager', $playlist) }}"
                           class="btn btn-sm btn-outline-primary" wire:navigate>
                            <i class="bi bi-pencil me-1"></i>{{ __('Bearbeiten') }}
                        </a>
                        <button class="btn btn-sm btn-outline-secondary"
                                wire:click="duplicate({{ $playlist->id }})"
                                wire:loading.attr="disabled" wire:target="duplicate"
                                title="{{ __('Duplizieren') }}">
                            <i class="bi bi-files"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger"
                                @click="$dispatch('confirm-dialog', { message: @js(__('Playlist „:name" wirklich löschen?', ['name' => $playlist->name])), confirmText: @js(__('Löschen')), onConfirm: () => $wire.delete({{ $playlist->id }}) })">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
