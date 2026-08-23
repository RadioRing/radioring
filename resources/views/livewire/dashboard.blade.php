<div wire:poll.10s>
@php $station = auth()->user()->currentStation(); @endphp

{{-- Header --}}
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-broadcast me-2 text-primary"></i>{{ $station?->name ?? __('Dashboard') }}
        </h4>
        @if($station)
            <span class="badge bg-{{ $station->status === 'active' ? 'success' : 'secondary' }} mt-1">
                {{ $station->status === 'active' ? __('Aktiv') : __('Pausiert') }}
            </span>
        @endif
    </div>
    <div class="d-flex gap-2">
        @if($station)
            <a href="{{ route('station.edit', $station) }}" class="btn btn-sm btn-outline-secondary" wire:navigate>
                <i class="bi bi-pencil me-1"></i>{{ __('Station bearbeiten') }}
            </a>
        @endif
        <a href="{{ route('station.select') }}" class="btn btn-sm btn-outline-primary" wire:navigate>
            <i class="bi bi-arrow-left-right me-1"></i>{{ __('Station wechseln') }}
        </a>
    </div>
</div>

@if($station)

    {{-- Statistik-Karten --}}
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-music-note-list fs-2 text-primary"></i>
                        <div>
                            <div class="text-muted small">{{ __('Playlisten') }}</div>
                            <div class="fw-bold fs-5">{{ $playlistCount }}</div>
                        </div>
                        <a href="{{ route('playlist.index') }}" class="ms-auto btn btn-sm btn-outline-primary" wire:navigate>
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-folder-music fs-2 text-primary"></i>
                        <div>
                            <div class="text-muted small">{{ __('Mediendateien') }}</div>
                            <div class="fw-bold fs-5">{{ $mediaCount }}</div>
                        </div>
                        <a href="{{ route('media.index') }}" class="ms-auto btn btn-sm btn-outline-primary" wire:navigate>
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-calendar3 fs-2 text-primary"></i>
                        <div>
                            <div class="text-muted small">{{ __('Wochenraster') }}</div>
                            <div class="fw-bold fs-5">{{ now()->translatedFormat('D, H:i') }}</div>
                        </div>
                        <a href="{{ route('hour-grid.index') }}" class="ms-auto btn btn-sm btn-outline-primary" wire:navigate>
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Live-Eingang (Streaming von außen via input.harbor + Traefik) --}}
    @if($liveStream)
        <div class="card mb-4" x-data="{ show: false }">
            <div class="card-header fw-medium d-flex align-items-center gap-2 py-2">
                <i class="bi bi-mic text-primary"></i>{{ __('Live-Eingang') }}
                <span class="text-muted-sm fw-normal">{{ __('Sende live von deinem Encoder (z. B. BUTT, Mixxx)') }}</span>
                @if($liveActive)
                    <span class="badge bg-danger d-flex align-items-center gap-1 ms-auto" style="font-size:.7rem">
                        <span class="rounded-circle bg-white d-inline-block" style="width:6px;height:6px;animation:blink 1s step-end infinite"></span>
                        {{ __('Live verbunden') }}
                    </span>
                @else
                    <span class="badge bg-secondary ms-auto" style="font-size:.7rem">{{ __('Nicht verbunden') }}</span>
                @endif
            </div>
            <div class="card-body">
                @if($liveActive)
                    <div class="alert alert-danger d-flex align-items-center gap-3 py-2 mb-3">
                        <i class="bi bi-broadcast fs-4"></i>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="fw-semibold text-truncate">
                                {{ $liveTitle ?: __('Live-Übernahme aktiv') }}
                                @if($liveArtist)
                                    <span class="fw-normal">&ndash; {{ $liveArtist }}</span>
                                @endif
                            </div>
                            <div class="small text-muted">
                                {{ __('Externer Encoder sendet') }}
                                @if($liveStartedAt)
                                    · {{ __('seit') }}
                                    <span class="font-monospace"
                                          x-data="nowPlaying()"
                                          data-started="{{ $liveStartedAt->timestamp }}"
                                          data-elapsed="{{ (int) $liveStartedAt->diffInSeconds(now()) }}"
                                          data-duration="0"
                                          x-text="fmt(elapsed)"
                                          wire:key="live-{{ $liveStartedAt->timestamp }}">{{ gmdate('G:i:s', (int) $liveStartedAt->diffInSeconds(now())) }}</span>
                                @endif
                                · {{ __('das automatische Programm ist unterbrochen') }}
                            </div>
                        </div>
                    </div>
                @endif
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-3">
                        <div class="text-muted small mb-1">{{ __('Server') }}</div>
                        <code class="user-select-all">{{ $liveStream['host'] }}</code>
                    </div>
                    <div class="col-6 col-lg-2">
                        <div class="text-muted small mb-1">{{ __('Port') }}</div>
                        <code>{{ $liveStream['port'] }}</code>
                        @unless($liveStream['tls'])
                            <span class="badge text-bg-secondary ms-1">{{ __('Klartext') }}</span>
                        @endunless
                    </div>
                    <div class="col-6 col-lg-2">
                        <div class="text-muted small mb-1">{{ __('Mountpoint') }}</div>
                        <code class="user-select-all">{{ $liveStream['mount'] }}</code>
                    </div>
                    <div class="col-6 col-lg-2">
                        <div class="text-muted small mb-1">{{ __('Benutzer') }}</div>
                        <code class="user-select-all">{{ $liveStream['username'] }}</code>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="text-muted small mb-1">{{ __('Passwort') }}</div>
                        <div class="d-flex align-items-center gap-2">
                            <code class="user-select-all" x-show="show">{{ $liveStream['password'] }}</code>
                            <code x-show="!show">••••••••••</code>
                            <button type="button" class="btn btn-sm btn-link p-0" @click="show = !show">
                                <i class="bi" :class="show ? 'bi-eye-slash' : 'bi-eye'"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <p class="text-muted-sm mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    {{ __('Eine Live-Übernahme unterbricht das automatische Programm, solange du sendest. Der Container muss dafür laufen.') }}
                </p>
            </div>
        </div>
    @endif

    {{-- Player-Widget --}}
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header d-flex align-items-center justify-content-between py-2"
             style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: calc(var(--bs-card-border-radius) - 1px) calc(var(--bs-card-border-radius) - 1px) 0 0;">
            @php
                $containerStatus = $stream?->status ?? 'stopped';
                $isRunning = $containerStatus === 'running';
                $isStarting = $containerStatus === 'starting';
            @endphp
            <div class="d-flex align-items-center gap-2">
                @if($onAir)
                    <span class="badge bg-danger d-flex align-items-center gap-1" style="font-size:.7rem">
                        <span class="rounded-circle bg-white d-inline-block" style="width:6px;height:6px;animation:blink 1s step-end infinite"></span>
                        ON AIR
                    </span>
                @else
                    <span class="badge bg-secondary" style="font-size:.7rem">OFFLINE</span>
                @endif
                {{-- Container-Status --}}
                @php
                    $badge = match($containerStatus) {
                        'running'  => ['bg-success', __('Container läuft')],
                        'starting' => ['bg-info text-dark', __('startet ...')],
                        'error'    => ['bg-danger', __('Fehler')],
                        default    => ['bg-secondary', __('gestoppt')],
                    };
                @endphp
                <span class="badge {{ $badge[0] }}" style="font-size:.7rem">
                    <i class="bi bi-hdd-network me-1"></i>{{ $badge[1] }}
                </span>
                <span class="text-white fw-medium small">{{ $station->name }}</span>
            </div>
            {{-- Steuerung --}}
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-light py-1 px-2"
                        wire:click="startStation"
                        title="{{ __('Station starten') }}"
                        wire:loading.attr="disabled" wire:target="startStation"
                        @if($isRunning || $isStarting) disabled @endif>
                    <i class="bi bi-play-fill"></i>
                </button>
                <button class="btn btn-sm btn-outline-light py-1 px-2"
                        wire:click="skipTrack"
                        title="{{ __('Nächster Track') }}"
                        @if(! $isRunning) disabled @endif>
                    <i class="bi bi-skip-end-fill"></i>
                </button>
                <button class="btn btn-sm btn-outline-warning py-1 px-2"
                        wire:click="stopStation"
                        title="{{ __('Station stoppen') }}"
                        wire:loading.attr="disabled" wire:target="stopStation"
                        @if(! $isRunning && ! $isStarting) disabled @endif>
                    <i class="bi bi-stop-fill"></i>
                </button>
                <button class="btn btn-sm btn-outline-success py-1 px-2"
                        wire:click="restartStation"
                        title="{{ __('Station neu starten') }}"
                        wire:loading.attr="disabled" wire:target="restartStation"
                        @if(! $isRunning) disabled @endif>
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
        </div>

        <div class="card-body py-3" style="background:#f8f9fa">
            @if($onAir)
                {{-- Aktueller Track. Alpine tickt elapsed/Restzeit/Balken sekündlich
                     client-seitig hoch; der wire:poll re-synct alle 10 s mit dem Server.
                     wire:key (Track-Startzeit) erzwingt einen Reset bei Track-Wechsel. --}}
                <div x-data="nowPlaying()"
                     data-started="{{ $state->now_playing_started_at->timestamp }}"
                     data-elapsed="{{ $elapsedSeconds }}"
                     data-duration="{{ (int) ($nowPlayingDuration ?? 0) }}"
                     wire:key="np-{{ $state->now_playing_started_at->timestamp }}">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="d-flex align-items-center justify-content-center rounded-2 bg-primary text-white flex-shrink-0"
                         style="width:44px;height:44px">
                        @php
                            $icon = match($nowPlayingSourceType) {
                                'news'         => 'bi-newspaper',
                                'weather'      => 'bi-cloud-sun',
                                'news_weather' => 'bi-newspaper',
                                'adbreak'      => 'bi-megaphone-fill',
                                default        => 'bi-music-note-beamed',
                            };
                        @endphp
                        <i class="bi {{ $icon }} fs-5"></i>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-semibold text-truncate">
                                {{ $nowPlayingTitle }}@if($nowPlayingArtist)<span class="fw-normal text-muted"> &ndash; {{ $nowPlayingArtist }}</span>@endif
                            </span>
                            @if($trackTotal > 0)
                                <span class="badge bg-secondary bg-opacity-25 text-secondary font-monospace flex-shrink-0" style="font-size:.65rem">
                                    {{ $trackPosition }}/{{ $trackTotal }}
                                </span>
                            @endif
                        </div>
                        <div class="text-muted small">
                            <span x-text="fmt(elapsed)">{{ gmdate('G:i:s', $elapsedSeconds) }}</span>
                            @if($nowPlayingDuration)
                                &nbsp;/&nbsp;{{ gmdate('G:i:s', $nowPlayingDuration) }}
                            @endif
                        </div>
                    </div>
                    @if($nowPlayingDuration)
                        <div class="text-muted small text-nowrap">
                            <span x-text="'-' + fmt(remaining)">-{{ gmdate('G:i:s', max(0, ($nowPlayingDuration ?? 0) - $elapsedSeconds)) }}</span>
                        </div>
                    @endif
                </div>

                {{-- Fortschrittsbalken --}}
                <div class="progress mb-2" style="height:5px">
                    <div class="progress-bar bg-primary" style="width:{{ $progressPercent }}%"
                         x-bind:style="'width: ' + percent + '%'"></div>
                </div>
                </div>

                {{-- Nächster Track (aus der projizierten Playlist) --}}
                @php
                    $nextProjected = $playlist->first(fn ($p) => $p->isUpcoming());
                @endphp
                @if($nextProjected)
                    <div class="text-muted small">
                        <i class="bi bi-skip-end me-1"></i><span class="fw-medium">{{ __('Als nächstes:') }}</span>
                        {{ $nextProjected->item->title }}@if($nextProjected->item->mediaFile?->artist) &ndash; {{ $nextProjected->item->mediaFile->artist }}@endif
                        @if($nextProjected->projectedStart)
                            <span class="opacity-75">({{ __('ca.') }} {{ $nextProjected->projectedStart->format('H:i') }})</span>
                        @endif
                    </div>
                @endif
            @else
                {{-- Kein aktiver Track --}}
                <div class="d-flex align-items-center gap-3 text-muted py-1">
                    <div class="d-flex align-items-center justify-content-center rounded-2 bg-secondary bg-opacity-10 flex-shrink-0"
                         style="width:44px;height:44px">
                        <i class="bi bi-pause-circle fs-4 text-secondary"></i>
                    </div>
                    <div>
                        <div class="fw-medium">{{ __('Kein Track aktiv') }}</div>
                        <div class="small">{{ __('Liquidsoap sendet nicht oder ist noch nicht verbunden.') }}</div>
                    </div>
                </div>
                <div class="progress mt-2" style="height:5px">
                    <div class="progress-bar bg-secondary" style="width:0%"></div>
                </div>
            @endif
        </div>
    </div>

    {{-- Durchgehende Playlist (mAirList-Stil): ab jetzt bis Tagesende, --}}
    {{-- Gespieltes ausgeblendet, "–" für per Hard-Start übersprungene Items. --}}
    @if($playlist->isNotEmpty())
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between py-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-list-ol text-primary"></i>
                    <span class="fw-medium">{{ __('Playlist') }}</span>
                    <span class="text-muted small">{{ $playlist->count() }} {{ __('Elemente') }}</span>
                </div>
                <a href="{{ route('hour-grid.index') }}" class="btn btn-sm btn-outline-secondary" wire:navigate>
                    <i class="bi bi-calendar3 me-1"></i>{{ __('Wochenraster') }}
                </a>
            </div>
            <div class="list-group list-group-flush" style="max-height:420px;overflow-y:auto">
                @php $lastHour = null; @endphp
                @foreach($playlist as $entry)
                    @php
                        $item = $entry->item;
                        $hour = (int) $entry->item->generatedPlaylist?->broadcast_hour;
                    @endphp

                    {{-- Stundentrenner / Hard-Start-Markierung --}}
                    @if($entry->isHardBoundary || $hour !== $lastHour)
                        <div class="list-group-item bg-light d-flex align-items-center gap-2 py-1 px-3 sticky-top"
                             wire:key="hour-{{ $item->generated_playlist_id }}">
                            <span class="font-monospace fw-semibold small">{{ sprintf('%02d:00', $hour) }}</span>
                            @if($entry->isHardBoundary)
                                <span class="badge bg-danger" style="font-size:.6rem">
                                    <i class="bi bi-lightning-charge-fill me-1"></i>{{ __('Harter Start') }}
                                </span>
                            @endif
                        </div>
                        @php $lastHour = $hour; @endphp
                    @endif

                    <div class="list-group-item d-flex align-items-center gap-2 py-2 px-3
                                {{ $entry->isPlaying ? 'list-group-item-primary' : '' }}
                                {{ $entry->isSkipped ? 'opacity-50' : '' }}"
                         wire:key="playlist-item-{{ $item->id }}">

                        {{-- Status-Icon --}}
                        <span class="text-nowrap" style="width:18px;text-align:center">
                            @if($entry->isPlaying)
                                <i class="bi bi-play-fill text-primary"></i>
                            @elseif($entry->isSkipped)
                                <i class="bi bi-slash-circle text-muted"></i>
                            @else
                                <i class="bi bi-chevron-right text-muted"></i>
                            @endif
                        </span>

                        {{-- Voraussichtliche Sendezeit (dynamisch, oder "–" wenn übersprungen) --}}
                        <span class="font-monospace text-nowrap {{ $entry->isSkipped ? 'text-muted text-decoration-line-through' : 'text-muted' }}"
                              style="font-size:.75rem;min-width:50px">
                            {{ $entry->projectedStart?->format('H:i:s') ?? '–' }}
                        </span>

                        {{-- Typ-Badge --}}
                        @php
                            $badgeClass = match($item->source_type) {
                                'news', 'weather', 'news_weather' => 'bg-info text-dark',
                                'adbreak'       => 'bg-danger',
                                'resolved_fill' => 'bg-success',
                                'manual'        => 'bg-warning text-dark',
                                default         => 'bg-light text-dark border',
                            };
                            $badgeIcon = match($item->source_type) {
                                'news'          => 'bi-newspaper',
                                'weather'       => 'bi-cloud-sun',
                                'news_weather'  => 'bi-newspaper',
                                'adbreak'       => 'bi-megaphone',
                                'resolved_fill' => 'bi-shuffle',
                                'manual'        => 'bi-pencil',
                                default         => 'bi-file-music',
                            };
                        @endphp
                        <span class="badge {{ $badgeClass }}" style="font-size:.6rem;min-width:52px">
                            <i class="bi {{ $badgeIcon }} me-1"></i>
                            {{ match($item->source_type) {
                                'news'          => __('News'),
                                'weather'       => __('Wetter'),
                                'news_weather'  => __('News+Wetter'),
                                'adbreak'       => __('Ad Break'),
                                'resolved_fill' => __('Fill'),
                                'manual'        => __('Manuell'),
                                default         => __('Template'),
                            } }}
                        </span>

                        {{-- Titel + Interpret --}}
                        <span class="text-truncate flex-grow-1 small {{ $entry->isPlaying ? 'fw-semibold' : '' }}">
                            {{ $item->title }}@if($item->mediaFile?->artist)<span class="text-muted"> &ndash; {{ $item->mediaFile->artist }}</span>@endif
                        </span>

                        {{-- Dauer --}}
                        @if($item->duration_seconds)
                            <span class="text-muted text-nowrap" style="font-size:.75rem">
                                {{ $item->durationFormatted() }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @elseif($station)
        <div class="alert alert-secondary d-flex align-items-center gap-2">
            <i class="bi bi-calendar-x fs-5"></i>
            <span>{{ __('Kein Rundown für die aktuelle Stunde vorhanden.') }}</span>
            <a href="{{ route('hour-grid.index') }}" class="btn btn-sm btn-outline-secondary ms-auto" wire:navigate>
                <i class="bi bi-calendar3 me-1"></i>{{ __('Wochenraster öffnen') }}
            </a>
        </div>
    @endif

@endif

@script
<script>
// Sekündlicher Client-Ticker für Abspielzeit/Progressbar.
//
// WICHTIG: Die Init-Werte kommen aus data-* Attributen, NICHT aus dem x-data-Ausdruck.
// Der x-data-Ausdruck muss über die wire:poll-Renders stabil bleiben – stünde dort die
// sich ändernde Server-Sekundenzahl, würde Alpine bei jedem Poll neu initialisieren
// (zweiter Timer, der gegen den ersten läuft → Balken „steckt fest"). data-started
// (Track-Startzeit) ändert sich nur beim Track-Wechsel; der wire:key sorgt dann für
// einen sauberen Reset. Getickt wird über die Wanduhr (Date.now), damit der Wert auch
// nach Tab-Throttling stimmt; eine einmalige Skew-Korrektur gleicht Client/Server-Uhr ab.
Alpine.data('nowPlaying', () => ({
    started: 0,
    duration: 0,
    skew: 0,
    elapsed: 0,
    _timer: null,
    init() {
        this.started = parseFloat(this.$el.dataset.started) || 0;
        this.duration = parseFloat(this.$el.dataset.duration) || 0;
        const serverElapsed = parseFloat(this.$el.dataset.elapsed) || 0;
        this.skew = (Date.now() / 1000) - (this.started + serverElapsed);
        this.tick();
        this._timer = setInterval(() => this.tick(), 500);
    },
    destroy() {
        clearInterval(this._timer);
    },
    tick() {
        let e = (Date.now() / 1000) - this.skew - this.started;
        if (e < 0) {
            e = 0;
        }
        if (this.duration > 0 && e > this.duration) {
            e = this.duration;
        }
        this.elapsed = e;
    },
    get percent() {
        return this.duration > 0 ? Math.min(100, (this.elapsed / this.duration) * 100) : 0;
    },
    get remaining() {
        return this.duration > 0 ? Math.max(0, this.duration - this.elapsed) : 0;
    },
    fmt(s) {
        s = Math.max(0, Math.floor(s));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const ss = s % 60;
        return h + ':' + String(m).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
    },
}));
</script>
@endscript
</div>

<style>
@keyframes blink {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0; }
}
</style>
