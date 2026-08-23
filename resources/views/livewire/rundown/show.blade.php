<div>
<style>
@keyframes blink { 0%, 100% { opacity:1; } 50% { opacity:0; } }
</style>
    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="fw-semibold mb-0">
                <i class="bi bi-clock-history me-2 text-primary"></i>
                {{ __('Rundown') }} – {{ $broadcastDate->translatedFormat('D, d.m.Y') }} {{ sprintf('%02d:00', $hour) }}
            </h4>
            <a href="{{ route('hour-grid.index') }}" class="text-muted-sm text-decoration-none" wire:navigate>
                <i class="bi bi-arrow-left me-1"></i>{{ __('Wochenraster') }}
            </a>
        </div>

        {{-- Stunden-Navigation --}}
        <div class="d-flex gap-2 align-items-center">
            @if($hour > 0)
                <a href="{{ route('rundown.show', [$date, $hour - 1]) }}"
                   class="btn btn-sm btn-outline-secondary" wire:navigate>
                    <i class="bi bi-chevron-left"></i>
                </a>
            @endif
            <span class="fw-medium">{{ sprintf('%02d:00', $hour) }}</span>
            @if($hour < 23)
                <a href="{{ route('rundown.show', [$date, $hour + 1]) }}"
                   class="btn btn-sm btn-outline-secondary" wire:navigate>
                    <i class="bi bi-chevron-right"></i>
                </a>
            @endif
        </div>
    </div>

    @if(! $rundown)
        {{-- Kein Rundown vorhanden --}}
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-calendar-x display-4 text-muted"></i>
                <p class="mt-3 text-muted">{{ __('Für diese Stunde wurde noch kein Rundown generiert.') }}</p>
                <button class="btn btn-primary" wire:click="regenerate">
                    <i class="bi bi-lightning-charge me-1"></i>{{ __('Jetzt generieren') }}
                </button>
            </div>
        </div>
    @else
        {{-- Status-Bar --}}
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            @php
                $statusClass = match($rundown->status) {
                    'ready'  => 'bg-success',
                    'played' => 'bg-secondary',
                    default  => 'bg-warning text-dark',
                };
            @endphp
            <span class="badge {{ $statusClass }}">{{ $rundown->status }}</span>

            @if($rundown->generated_at)
                <span class="text-muted small">
                    <i class="bi bi-clock me-1"></i>{{ __('Generiert:') }} {{ $rundown->generated_at->format('d.m.Y H:i') }}
                </span>
            @endif

            <span class="text-muted small">
                <i class="bi bi-music-note-beamed me-1"></i>{{ $rundown->items->count() }} {{ __('Tracks') }}
            </span>

            @if($rundown->playlist)
                <span class="text-muted small">
                    <i class="bi bi-collection me-1"></i>{{ $rundown->playlist->name }}
                </span>
            @endif

            <div class="ms-auto d-flex align-items-center gap-2">
                @if($lockedBelowPosition >= 0)
                    <span class="badge bg-danger d-flex align-items-center gap-1" style="font-size:.7rem">
                        <span class="rounded-circle bg-white d-inline-block" style="width:6px;height:6px;animation:blink 1s step-end infinite"></span>
                        ON AIR
                    </span>
                @endif
                @if(! $rundown->isPlayed())
                    @if($lockedBelowPosition >= 0)
                        <span class="text-muted small">
                            <i class="bi bi-lock me-1"></i>{{ __('Aktiver Rundown – gespielte Tracks sind gesperrt') }}
                        </span>
                        <button class="btn btn-sm btn-outline-danger"
                                @click="$dispatch('confirm-dialog', { message: @js(__('Dieser Rundown läuft gerade LIVE. Neu generieren verwirft alle manuellen Änderungen und startet die Wiedergabe von vorn. Fortfahren?')), confirmText: @js(__('Live neu generieren')), onConfirm: () => $wire.regenerate() })">
                            <i class="bi bi-arrow-clockwise me-1"></i>{{ __('Live neu generieren') }}
                        </button>
                    @elseif($rundown->isReady())
                        <button class="btn btn-sm btn-outline-warning"
                                @click="$dispatch('confirm-dialog', { message: @js(__('Dieser Rundown wurde bereits generiert. Neu generieren und alle manuellen Änderungen verwerfen?')), confirmText: @js(__('Neu generieren')), confirmClass: 'btn-warning', onConfirm: () => $wire.regenerate() })">
                            <i class="bi bi-arrow-clockwise me-1"></i>{{ __('Neu generieren') }}
                        </button>
                    @else
                        <button class="btn btn-sm btn-primary" wire:click="regenerate">
                            <i class="bi bi-lightning-charge me-1"></i>{{ __('Generieren') }}
                        </button>
                    @endif
                @endif
            </div>
        </div>

        {{-- Track-Liste --}}
        @php
            $totalSeconds = $rundown->items->sum('duration_seconds');
            $totalFormatted = $totalSeconds
                ? sprintf('%d:%02d', intdiv($totalSeconds, 60), $totalSeconds % 60)
                : null;
            $endTime = $rundown->items->last()?->absolute_broadcast_at
                ? $rundown->items->last()->absolute_broadcast_at->addSeconds($rundown->items->last()->duration_seconds ?? 0)->format('H:i:s')
                : null;
        @endphp
        <div class="card">
            <div class="card-header fw-medium d-flex justify-content-between align-items-center">
                <span>{{ __('Tracks') }} ({{ $rundown->items->count() }})</span>
                @if($totalFormatted)
                    <span class="text-muted small fw-normal">
                        <i class="bi bi-hourglass-split me-1"></i>{{ $totalFormatted }}
                        @if($endTime)
                            <span class="ms-2 opacity-75">→ {{ $endTime }}</span>
                        @endif
                    </span>
                @endif
            </div>

            @if($rundown->items->isEmpty())
                <div class="card-body text-center text-muted py-4">
                    {{ __('Keine Tracks – Liquidsoap fällt auf Silence zurück.') }}
                </div>
            @else
                <div class="list-group list-group-flush">
                    @foreach($rundown->items as $item)
                        @php
                            // ▶ = tatsächlich laufender Track (now_playing).
                            // „Gespielt" (faded + Häkchen) richtet sich nach dem ECHTEN
                            // Airplay (playingPosition) – NICHT am Pull-Cursor, der durch
                            // prefetch vorausläuft (vorgeladene Tracks sind noch nicht gespielt).
                            // Die Edit-Sperre dagegen richtet sich am Pull-Cursor
                            // (bereits heruntergeladen → nicht mehr änderbar).
                            $isPlaying = $playingPosition >= 0 && $item->position === $playingPosition;
                            $isPlayed  = $playingPosition >= 0 && $item->position < $playingPosition;
                            $isLocked  = $lockedBelowPosition >= 0 && $item->position < $lockedBelowPosition && ! $isPlaying;
                        @endphp
                        <div wire:key="item-{{ $item->id }}">
                            <div class="list-group-item d-flex align-items-center gap-3 py-2
                                        {{ $isPlaying ? 'list-group-item-primary' : '' }}
                                        {{ $isPlayed  ? 'opacity-50' : '' }}
                                        {{ ! $isPlaying && in_array($item->source_type, ['news', 'weather', 'news_weather']) ? 'bg-info bg-opacity-10' : '' }}
                                        {{ ! $isPlaying && $item->source_type === 'adbreak' ? 'bg-danger bg-opacity-10' : '' }}">

                                {{-- Spiel-Status --}}
                                <span class="text-nowrap" style="width:18px;text-align:center">
                                    @if($isPlaying)
                                        <i class="bi bi-play-fill text-primary"></i>
                                    @elseif($isPlayed)
                                        <i class="bi bi-check text-success"></i>
                                    @elseif($isLocked)
                                        <i class="bi bi-lock text-muted opacity-50" title="{{ __('Vorgeladen – nicht mehr änderbar') }}"></i>
                                    @else
                                        <span class="text-muted font-monospace" style="font-size:.75rem">{{ $item->position + 1 }}</span>
                                    @endif
                                </span>

                                {{-- Absolute Uhrzeit --}}
                                <span class="fw-bold text-nowrap font-monospace"
                                      style="min-width:54px;font-size:.85rem">
                                    {{ $item->absolute_broadcast_at?->format('H:i:s') ?? '–' }}
                                </span>

                                {{-- Source-Badge --}}
                                @php
                                    $sourceBadge = match($item->source_type) {
                                        'news'          => ['bg-info text-dark', 'bi-newspaper', __('News')],
                                        'weather'       => ['bg-info text-dark', 'bi-cloud-sun', __('Wetter')],
                                        'news_weather'  => ['bg-info text-dark', 'bi-newspaper', __('News+Wetter')],
                                        'adbreak'       => ['bg-danger', 'bi-megaphone', __('Ad Break')],
                                        'resolved_fill' => ['bg-success', 'bi-shuffle', __('Fill')],
                                        'manual'        => ['bg-warning text-dark', 'bi-pencil', __('Manuell')],
                                        default         => ['bg-light text-dark border', 'bi-file-music', __('Template')],
                                    };
                                @endphp
                                <span class="badge {{ $sourceBadge[0] }} text-nowrap" style="font-size:.65rem;min-width:58px">
                                    <i class="bi {{ $sourceBadge[1] }} me-1"></i>{{ $sourceBadge[2] }}
                                </span>

                                {{-- Info --}}
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="text-truncate fw-medium small {{ $isPlaying ? 'fw-semibold' : '' }}">
                                        {{ $item->title }}
                                    </div>
                                    @if($item->durationFormatted())
                                        <div class="text-muted" style="font-size:.75rem">
                                            <i class="bi bi-clock me-1"></i>{{ $item->durationFormatted() }}
                                        </div>
                                    @endif
                                </div>

                                @if(! $rundown->isPlayed() && ! $isLocked)
                                    {{-- Ersetzen --}}
                                    <button class="btn btn-sm btn-outline-secondary"
                                            wire:click="startReplacing({{ $item->id }})"
                                            title="{{ __('Track ersetzen') }}">
                                        <i class="bi bi-arrow-left-right"></i>
                                    </button>

                                    {{-- Entfernen --}}
                                    <button class="btn btn-sm btn-outline-danger"
                                            @click="$dispatch('confirm-dialog', { message: @js(__('Track entfernen?')), confirmText: @js(__('Entfernen')), onConfirm: () => $wire.removeItem({{ $item->id }}) })">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @elseif($isLocked)
                                    <i class="bi bi-lock text-muted opacity-50" title="{{ __('Bereits gespielt') }}"></i>
                                @endif
                            </div>

                            {{-- Inline-Ersetzungsformular --}}
                            @if($replacingItemId === $item->id)
                                <div class="list-group-item bg-light border-top-0 py-2 px-3">
                                    <p class="small fw-medium mb-2">{{ __('Track ersetzen durch:') }}</p>
                                    <div class="mb-2">
                                        <div class="input-group input-group-sm mb-2">
                                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                                            <input type="text" wire:model.live.debounce.250ms="librarySearch"
                                                   class="form-control" placeholder="{{ __('Suche in Bibliothek...') }}" autofocus>
                                        </div>
                                        @error('selectedMediaFileId')
                                            <div class="alert alert-danger py-1 px-2 small mb-2">{{ __('Bitte eine Datei auswählen.') }}</div>
                                        @enderror
                                        @if($libraryFiles->isEmpty())
                                            <p class="text-muted small">{{ __('Keine Dateien gefunden.') }}</p>
                                        @else
                                            <div class="list-group" style="max-height:180px;overflow-y:auto">
                                                @foreach($libraryFiles as $file)
                                                    <button type="button"
                                                            wire:click="$set('selectedMediaFileId', {{ $file->id }})"
                                                            class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-1 px-2 small
                                                                   {{ $selectedMediaFileId === $file->id ? 'active' : '' }}">
                                                        <span class="badge bg-{{ $file->type === 'music' ? 'primary' : 'warning text-dark' }}">
                                                            {{ $file->type === 'music' ? __('Musik') : __('Jingle') }}
                                                        </span>
                                                        <span class="text-truncate flex-grow-1">{{ $file->title }}</span>
                                                        @if($file->durationFormatted())
                                                            <span class="text-nowrap opacity-75">{{ $file->durationFormatted() }}</span>
                                                        @endif
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-primary"
                                                wire:click="replaceItem"
                                                :disabled="!$wire.selectedMediaFileId">
                                            <i class="bi bi-check-lg me-1"></i>{{ __('Ersetzen') }}
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" wire:click="cancelReplacing">
                                            {{ __('Abbrechen') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
