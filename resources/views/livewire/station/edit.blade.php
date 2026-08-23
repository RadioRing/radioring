<div>
    <div class="mb-4">
        <h4 class="fw-semibold mb-0">{{ __('Station bearbeiten') }}</h4>
        <p class="text-muted-sm mt-1">{{ $station->name }}</p>
    </div>

    <div class="card mb-4" style="max-width: 480px;">
        <div class="card-body">
            <form wire:submit="save">
                <div class="mb-3">
                    <label for="name" class="form-label fw-medium">{{ __('Stationsname') }}</label>
                    <input id="name" type="text" wire:model="name"
                           class="form-control @error('name') is-invalid @enderror">
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="status" class="form-label fw-medium">{{ __('Status') }}</label>
                    <select id="status" wire:model="status"
                            class="form-select @error('status') is-invalid @enderror">
                        <option value="active">{{ __('Aktiv') }}</option>
                        <option value="paused">{{ __('Pausiert') }}</option>
                    </select>
                    @error('status')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <div class="text-muted-sm mb-1">{{ __('Slug') }}</div>
                    <code>{{ $station->slug }}</code>
                </div>

                <hr>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="regenerateRundownsNightly" wire:model="regenerateRundownsNightly">
                    <label class="form-check-label fw-medium" for="regenerateRundownsNightly">
                        {{ __('Rundowns nachts neu generieren') }}
                    </label>
                    <div class="text-muted-sm">
                        {{ __('Generiert die Rundowns des Folgetags jede Nacht neu – auch wenn sie bereits erstellt wurden. So fließen neue Musik-Uploads automatisch ein. Bereits gespielte Stunden bleiben unangetastet.') }}
                    </div>
                </div>

                <hr>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>{{ __('Speichern') }}
                </button>
                <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary ms-1" wire:navigate>
                    {{ __('Abbrechen') }}
                </a>
            </form>
        </div>
    </div>

    @if($station->stereo_tool_enabled)
        <div class="card mb-4" style="max-width: 480px;">
            <div class="card-body">
                <h6 class="fw-semibold">
                    <i class="bi bi-sliders me-1 text-primary"></i>{{ __('Stereo Tool (Audio-Processing)') }}
                </h6>
                <p class="text-muted-sm">{{ __('Von einem Administrator für diese Station freigeschaltet. Hinterlege deinen Thimeo-Lizenzschlüssel und wähle ein Preset. Ohne gültige Lizenz und Preset bleibt das Processing inaktiv.') }}</p>

                <form wire:submit="save">
                    <div class="mb-3">
                        <label for="stereoToolLicenseKey" class="form-label fw-medium">{{ __('Lizenzschlüssel') }}</label>
                        <input id="stereoToolLicenseKey" type="text" wire:model="stereoToolLicenseKey"
                               class="form-control @error('stereoToolLicenseKey') is-invalid @enderror"
                               autocomplete="off" placeholder="{{ __('Thimeo-Lizenzschlüssel') }}">
                        @error('stereoToolLicenseKey')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="stereoToolPreset" class="form-label fw-medium">{{ __('Preset') }}</label>
                        <select id="stereoToolPreset" wire:model="stereoToolPreset"
                                class="form-select @error('stereoToolPreset') is-invalid @enderror">
                            <option value="">{{ __('– kein Processing –') }}</option>
                            @foreach($stereoToolPresets as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('stereoToolPreset')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i>{{ __('Speichern') }}
                    </button>
                </form>
            </div>
        </div>
    @endif

    <div class="card mb-4" style="max-width: 480px;">
        <div class="card-body">
            <h6 class="fw-semibold">{{ __('Team / Zugriff') }}</h6>
            <p class="text-muted-sm">{{ __('Erteile anderen registrierten Nutzern Zugriff, um die Station gemeinsam zu verwalten.') }}</p>

            <ul class="list-group list-group-flush mb-3">
                @foreach ($members as $member)
                    <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                        <div>
                            <div class="fw-medium">{{ $member->name }}</div>
                            <div class="text-muted-sm">{{ $member->email }}</div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            @if ($member->pivot->role === 'owner')
                                <span class="badge text-bg-primary">{{ __('Besitzer') }}</span>
                            @else
                                <span class="badge text-bg-secondary">{{ __('Editor') }}</span>
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        @click="$dispatch('confirm-dialog', { message: @js(__('Zugriff für :name entziehen?', ['name' => $member->name])), confirmText: @js(__('Entziehen')), onConfirm: () => $wire.removeMember({{ $member->id }}) })">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <form wire:submit="addMember">
                <label for="memberEmail" class="form-label fw-medium">{{ __('Nutzer per E-Mail hinzufügen') }}</label>
                <div class="input-group">
                    <input id="memberEmail" type="email" wire:model="memberEmail"
                           class="form-control @error('memberEmail') is-invalid @enderror"
                           placeholder="email@example.com">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i>{{ __('Hinzufügen') }}
                    </button>
                    @error('memberEmail')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </form>
        </div>
    </div>

    <div class="card border-danger" style="max-width: 480px;">
        <div class="card-body">
            <h6 class="text-danger fw-semibold">{{ __('Station löschen') }}</h6>
            <p class="text-muted-sm">{{ __('Löscht die Station und alle zugehörigen Daten unwiderruflich.') }}</p>
            <button type="button" class="btn btn-outline-danger btn-sm"
                    data-bs-toggle="modal" data-bs-target="#deleteStationModal">
                <i class="bi bi-trash me-1"></i>{{ __('Station löschen') }}
            </button>
        </div>
    </div>

    <div class="modal fade" id="deleteStationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Station wirklich löschen?') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>{{ __('Die Station „:name" und alle zugehörigen Daten werden unwiderruflich gelöscht.', ['name' => $station->name]) }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Abbrechen') }}</button>
                    <button type="button" class="btn btn-danger" wire:click="delete">
                        <i class="bi bi-trash me-1"></i>{{ __('Ja, löschen') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
