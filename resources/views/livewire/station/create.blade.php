<div>
    <div class="mb-4">
        <h4 class="fw-semibold mb-0">{{ __('Neue Radiostation erstellen') }}</h4>
        @if($showQuota)
            <p class="text-muted-sm mt-1">
                {{ __(':used von :quota Stationen genutzt', ['used' => $used, 'quota' => $quota]) }}
            </p>
        @endif
    </div>

    @if($accessibleStations->isNotEmpty())
        <div class="card mb-4" style="max-width: 480px;">
            <div class="card-body">
                <h6 class="fw-semibold mb-1">{{ __('Switch to an existing station') }}</h6>
                <p class="text-muted-sm mb-3">{{ __('You already have access to these stations.') }}</p>
                <div class="list-group">
                    @foreach($accessibleStations as $existingStation)
                        <button type="button" wire:click="choose({{ $existingStation->id }})"
                                class="list-group-item list-group-item-action d-flex align-items-center justify-content-between">
                            <span>
                                <i class="bi bi-broadcast-pin me-1 text-primary"></i>{{ $existingStation->name }}
                            </span>
                            <span class="badge bg-secondary">
                                {{ \App\Enums\StationRole::tryFrom($existingStation->pivot->role)?->label() }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if(!$canCreate)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            {{ __('Du hast dein Limit von :quota Stationen erreicht. Bitte einen Admin um mehr Kontingent.', ['quota' => $quota]) }}
        </div>
    @else
        <div class="card" style="max-width: 480px;">
            <div class="card-body">
                <form wire:submit="save">
                    <div class="mb-3">
                        <label for="name" class="form-label fw-medium">{{ __('Stationsname') }}</label>
                        <input id="name" type="text" wire:model="name"
                               class="form-control @error('name') is-invalid @enderror"
                               placeholder="{{ __('z.B. Mein Webradio') }}"
                               autofocus>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">{{ __('Mindestens 2 Zeichen. Wird als Slug für Liquidsoap genutzt.') }}</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg me-1"></i>{{ __('Station erstellen') }}
                        </button>
                        @if($accessibleStations->isNotEmpty())
                            <a href="{{ route('station.select') }}" class="btn btn-outline-secondary" wire:navigate>
                                {{ __('Abbrechen') }}
                            </a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
