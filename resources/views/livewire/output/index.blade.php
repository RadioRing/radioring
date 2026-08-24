<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-broadcast me-2 text-primary"></i>{{ __('Stream-Ausgänge') }}
        </h4>
        @unless($showForm)
            <button class="btn btn-primary btn-sm" wire:click="createNew">
                <i class="bi bi-plus-lg me-1"></i>{{ __('Ausgang hinzufügen') }}
            </button>
        @endunless
    </div>

    <p class="text-muted-sm mb-4" style="max-width: 640px;">
        {{ __('Hier legst du fest, wohin der Liquidsoap-Container den Stream sendet – z.B. zu laut.fm oder einem eigenen Icecast-Server. Die Zugangsdaten bekommst du von deinem Streaming-Anbieter.') }}
        @if($internalAvailable)
            {{ __('Or you use the internal server: RadioRing then broadcasts by itself and hands you a ready-made stream URL for your website.') }}
        @endif
    </p>

    {{-- Formular --}}
    @if($showForm)
        <div class="card mb-4" style="max-width: 640px;">
            <div class="card-body">
                <h6 class="fw-semibold mb-3">
                    {{ $editingId ? __('Ausgang bearbeiten') : __('Neuer Ausgang') }}
                </h6>
                <form wire:submit="save">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">{{ __('Typ') }}</label>
                            <select wire:model.live="type" class="form-select @error('type') is-invalid @enderror">
                                <option value="lautfm">laut.fm</option>
                                <option value="icecast">{{ __('Icecast (eigener Server)') }}</option>
                                @if($internalAvailable)
                                    <option value="internal">{{ __('Internal server (RadioRing)') }}</option>
                                @endif
                            </select>
                            @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        @if($type === 'internal')
                            <div class="col-12">
                                <div class="alert alert-info mb-0 small">
                                    <i class="bi bi-info-circle-fill me-1"></i>
                                    {{ __('RadioRing starts its own streaming server next to this station. Host, user and password are managed automatically.') }}
                                </div>
                            </div>
                        @endif

                        <div class="col-md-8" @if($type === 'internal') hidden @endif>
                            <label class="form-label">{{ __('Server / Host') }}</label>
                            <input type="text" wire:model="host"
                                   class="form-control @error('host') is-invalid @enderror"
                                   placeholder="z.B. stream.laut.fm">
                            @error('host') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4" @if($type === 'internal') hidden @endif>
                            <label class="form-label">{{ __('Port') }}</label>
                            <input type="number" wire:model="port"
                                   class="form-control @error('port') is-invalid @enderror" placeholder="8000">
                            @error('port') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="{{ $type === 'internal' ? 'col-md-8' : 'col-md-6' }}">
                            <label class="form-label">{{ __('Mountpoint') }}</label>
                            <input type="text" wire:model="mount"
                                   class="form-control @error('mount') is-invalid @enderror"
                                   placeholder="z.B. /meinsender">
                            @error('mount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            @if($type === 'internal')
                                <div class="form-text">
                                    {{ __('Last part of the public address:') }}
                                    <code>https://{{ $station->streamHost() }}/{{ ltrim($mount, '/') ?: 'stream' }}</code>
                                </div>
                            @endif
                        </div>
                        <div class="col-md-6" @if($type === 'internal') hidden @endif>
                            <label class="form-label">{{ __('Benutzername') }}</label>
                            <input type="text" wire:model="username"
                                   class="form-control @error('username') is-invalid @enderror"
                                   placeholder="source">
                            @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-8" @if($type === 'internal') hidden @endif>
                            <label class="form-label">{{ __('Passwort') }}</label>
                            <input type="password" wire:model="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   placeholder="{{ $editingId ? __('Leer lassen = unverändert') : '' }}"
                                   autocomplete="new-password">
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Bitrate') }}</label>
                            <select wire:model="bitrate" class="form-select @error('bitrate') is-invalid @enderror">
                                @foreach([64, 96, 128, 192, 256, 320] as $br)
                                    <option value="{{ $br }}">{{ $br }} kbit/s</option>
                                @endforeach
                            </select>
                            @error('bitrate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" wire:model="enabled" class="form-check-input" id="outputEnabled">
                                <label class="form-check-label" for="outputEnabled">{{ __('Aktiv') }}</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary btn-sm">
                            {{ $editingId ? __('Speichern') : __('Hinzufügen') }}
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="cancel">
                            {{ __('Abbrechen') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Liste --}}
    @if($outputs->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-broadcast display-4 text-muted"></i>
            <p class="mt-3 text-muted">{{ __('Noch kein Ausgang konfiguriert.') }}</p>
            <p class="text-muted-sm">{{ __('Ohne Ausgang sendet der Container nirgendwohin.') }}</p>
        </div>
    @else
        <div class="list-group" style="max-width: 760px;">
            @foreach($outputs as $output)
                <div class="list-group-item d-flex align-items-center justify-content-between py-3">
                    <div class="d-flex align-items-center gap-3">
                        @php
                            $icon = match ($output->type) {
                                'lautfm' => 'bi-soundwave',
                                'internal' => 'bi-house-up',
                                default => 'bi-hdd-network',
                            };
                            $label = match ($output->type) {
                                'lautfm' => 'laut.fm',
                                'internal' => __('Internal server'),
                                default => __('Icecast'),
                            };
                            $publicUrl = $output->isInternal() ? $station->internalStreamUrl($output) : null;
                        @endphp
                        <i class="bi {{ $icon }} fs-5 {{ $output->enabled ? 'text-primary' : 'text-muted' }}"></i>
                        <div>
                            <div class="fw-medium">
                                {{ $label }}
                                @if($output->enabled)
                                    <span class="badge text-bg-success ms-1">{{ __('Aktiv') }}</span>
                                @else
                                    <span class="badge text-bg-secondary ms-1">{{ __('Inaktiv') }}</span>
                                @endif
                                @if($publicUrl && $output->enabled && $listeners !== null)
                                    <span class="badge text-bg-light border ms-1" title="{{ __('Listeners right now') }}">
                                        <i class="bi bi-people me-1"></i>{{ $listeners }}
                                    </span>
                                @endif
                            </div>
                            <div class="text-muted-sm mt-1">
                                @if($publicUrl)
                                    <a href="{{ $publicUrl }}" target="_blank" rel="noopener"><code>{{ $publicUrl }}</code></a>
                                    <span class="ms-2">· {{ $output->bitrate }} kbit/s</span>
                                @else
                                    <code>{{ $output->host }}:{{ $output->port }}{{ $output->mount }}</code>
                                    <span class="ms-2">· {{ $output->bitrate }} kbit/s</span>
                                    <span class="ms-2">· {{ __('User') }}: {{ $output->username }}</span>
                                @endif
                            </div>
                            @if($publicUrl)
                                <details class="mt-2">
                                    <summary class="text-muted-sm" style="cursor: pointer;">{{ __('Embed on your website') }}</summary>
                                    <pre class="bg-body-secondary rounded p-2 mt-2 mb-0 small user-select-all"><code>{{ '<audio controls src="'.$publicUrl.'"></audio>' }}</code></pre>
                                </details>
                            @endif
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" wire:click="toggle({{ $output->id }})"
                                title="{{ $output->enabled ? __('Deaktivieren') : __('Aktivieren') }}">
                            <i class="bi {{ $output->enabled ? 'bi-pause' : 'bi-play' }}"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-primary" wire:click="edit({{ $output->id }})">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger"
                                @click="$dispatch('confirm-dialog', { message: @js(__('Diesen Ausgang wirklich löschen?')), confirmText: @js(__('Löschen')), onConfirm: () => $wire.delete({{ $output->id }}) })">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="alert alert-info d-flex align-items-center gap-2 mt-4" style="max-width: 760px;">
            <i class="bi bi-info-circle-fill"></i>
            <span class="small">{{ __('Änderungen an Ausgängen greifen erst nach einem Neustart des Liquidsoap-Containers.') }}</span>
        </div>
    @endif
</div>
