<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">{{ __('Radiostation auswählen') }}</h4>
        @if(auth()->user()->canCreateStation())
            <a href="{{ route('station.create') }}" class="btn btn-primary btn-sm" wire:navigate>
                <i class="bi bi-plus-lg me-1"></i>{{ __('Neue Station') }}
            </a>
        @endif
    </div>

    @if($stations->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-broadcast display-4 text-muted"></i>
            <p class="mt-3 text-muted">{{ __('Du hast noch keine Radiostationen.') }}</p>
            <a href="{{ route('station.create') }}" class="btn btn-primary" wire:navigate>
                <i class="bi bi-plus-lg me-1"></i>{{ __('Erste Station erstellen') }}
            </a>
        </div>
    @else
        <div class="row g-3">
            @foreach($stations as $station)
                <div class="col-md-4">
                    <div class="card h-100 {{ auth()->user()->currentStation()?->id === $station->id ? 'border-primary' : '' }}">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <h5 class="card-title fw-semibold mb-1">
                                        <i class="bi bi-broadcast-pin me-1 text-primary"></i>
                                        {{ $station->name }}
                                    </h5>
                                    <span class="badge bg-{{ $station->status === 'active' ? 'success' : 'secondary' }}">
                                        {{ $station->status === 'active' ? __('Aktiv') : __('Pausiert') }}
                                    </span>
                                </div>
                                <a href="{{ route('station.edit', $station) }}"
                                   class="btn btn-sm btn-outline-secondary" wire:navigate>
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <button wire:click="choose({{ $station->id }})"
                                    class="btn btn-{{ auth()->user()->currentStation()?->id === $station->id ? 'primary' : 'outline-primary' }} w-100 btn-sm">
                                @if(auth()->user()->currentStation()?->id === $station->id)
                                    <i class="bi bi-check-lg me-1"></i>{{ __('Aktive Station') }}
                                @else
                                    {{ __('Diese Station auswählen') }}
                                @endif
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if(!auth()->user()->canCreateStation())
            <p class="text-muted-sm mt-3">
                <i class="bi bi-info-circle me-1"></i>
                {{ __('Du hast dein Limit von :quota Stationen erreicht.', ['quota' => auth()->user()->tenant?->station_quota]) }}
            </p>
        @endif
    @endif
</div>
