<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-card-list me-2 text-primary"></i>{{ __('Protokoll') }}
        </h4>
        <span class="text-muted-sm">{{ $station->name }}</span>
    </div>

    {{-- Filter --}}
    <div class="row g-2 align-items-end mb-3">
        <div class="col-auto">
            <label class="form-label small text-muted mb-1">{{ __('Tag') }}</label>
            <input type="date" wire:model.live="date" class="form-control form-control-sm">
        </div>
        <div class="col-auto">
            <label class="form-label small text-muted mb-1">{{ __('Ereignis') }}</label>
            <select wire:model.live="filter" class="form-select form-select-sm">
                <option value="">{{ __('Alle') }}</option>
                <option value="playlist">{{ __('Titel – Playlist') }}</option>
                <option value="live_track">{{ __('Titel – Live') }}</option>
                <option value="live_switch">{{ __('Live-Wechsel') }}</option>
                <option value="rundown">{{ __('Rundown generiert') }}</option>
            </select>
        </div>
        <div class="col-12 col-sm">
            <label class="form-label small text-muted mb-1">{{ __('Suche') }}</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" wire:model.live.debounce.300ms="search"
                       class="form-control" placeholder="{{ __('Titel oder Interpret ...') }}">
            </div>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary btn-sm" wire:click="resetFilters">
                <i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Zurücksetzen') }}
            </button>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:9rem">{{ __('Zeit') }}</th>
                        <th style="width:11rem">{{ __('Ereignis') }}</th>
                        <th>{{ __('Details') }}</th>
                        <th>{{ __('Interpret') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @php $prevTrack = null; @endphp
                    @forelse($entries as $entry)
                        @php
                            $isTrack = $entry->event === \App\Models\StationLog::EVENT_TRACK;
                            $isRepeat = $isTrack && $prevTrack !== null && $entry->title !== null && $entry->title === $prevTrack;
                            $prevTrack = $isTrack ? $entry->title : null;
                        @endphp
                        <tr wire:key="log-{{ $entry->id }}">
                            <td class="text-muted-sm font-monospace">{{ $entry->occurred_at->format('d.m. H:i:s') }}</td>
                            <td>
                                @switch($entry->event)
                                    @case(\App\Models\StationLog::EVENT_TRACK)
                                        @if($entry->source === 'live')
                                            <span class="badge text-bg-danger-subtle text-danger">
                                                <i class="bi bi-mic-fill me-1"></i>{{ __('Titel · Live') }}
                                            </span>
                                        @else
                                            <span class="badge text-bg-primary-subtle text-primary">{{ __('Titel · Playlist') }}</span>
                                        @endif
                                        @break
                                    @case(\App\Models\StationLog::EVENT_LIVE_STARTED)
                                        <span class="badge text-bg-danger-subtle text-danger">
                                            <i class="bi bi-broadcast me-1"></i>{{ __('Live-Start') }}
                                        </span>
                                        @break
                                    @case(\App\Models\StationLog::EVENT_LIVE_STOPPED)
                                        <span class="badge text-bg-secondary-subtle text-secondary">
                                            <i class="bi bi-stop-circle me-1"></i>{{ __('Live-Ende') }}
                                        </span>
                                        @break
                                    @case(\App\Models\StationLog::EVENT_RUNDOWN_GENERATED)
                                        <span class="badge text-bg-success-subtle text-success">
                                            <i class="bi bi-arrow-repeat me-1"></i>{{ __('Rundown') }}
                                        </span>
                                        @break
                                @endswitch
                            </td>
                            <td class="fw-medium">
                                {{ $entry->title ?? $entry->message ?? '–' }}
                                @if($isRepeat)
                                    <span class="badge text-bg-warning-subtle text-warning ms-1"
                                          title="{{ __('Gleicher Titel direkt nacheinander – möglicher Streaming-Fehler.') }}">
                                        <i class="bi bi-arrow-repeat me-1"></i>{{ __('Wiederholung') }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-muted-sm">{{ $entry->artist ?? '–' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">{{ __('Keine Einträge für diese Filter.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $entries->links() }}
    </div>
</div>
