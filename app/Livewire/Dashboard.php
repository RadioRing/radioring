<?php

namespace App\Livewire;

use App\Contracts\ContainerServiceInterface;
use App\Jobs\StartStationContainer;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Services\IcecastStatusService;
use App\Services\LiquidsoapCommandService;
use App\Services\LiquidsoapStateService;
use App\Services\PlaylistProjectionService;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    /**
     * Kulanz in Sekunden, um die ein Track seine Dauer überschreiten darf, bevor er
     * als beendet/Stille gilt (deckt kleine Dauer-Ungenauigkeiten und den kurzen
     * Spalt bis zum nächsten on_metadata-Callback ab).
     */
    private const NOW_PLAYING_STALE_GRACE_SECONDS = 60;

    public function mount(): void
    {
        $user = auth()->user();
        $current = $user->currentStation();

        if ($current) {
            return;
        }

        $stations = $user->accessibleStations()->get();

        if ($stations->count() === 0) {
            $this->redirect(route('station.create'), navigate: true);

            return;
        }

        if ($stations->count() === 1) {
            $user->setCurrentStation($stations->first());

            return;
        }

        $this->redirect(route('station.select'), navigate: true);
    }

    public function startStation(ContainerServiceInterface $containers): void
    {
        $station = $this->requireStation();

        if (! $containers->isConfigured()) {
            $this->dispatch('notify', message: __('The container service is not configured.'), type: 'info');

            return;
        }

        $stream = $station->ensureStream();
        $stream->update(['status' => 'starting']);

        StartStationContainer::dispatch($station->id);

        $this->dispatch('notify', message: __('Container wird gestartet ...'), type: 'success');
    }

    public function stopStation(ContainerServiceInterface $containers): void
    {
        $station = $this->requireStation();

        if (! $containers->isConfigured()) {
            $this->dispatch('notify', message: __('The container service is not configured.'), type: 'info');

            return;
        }

        $ok = $containers->stopStationContainer($station);

        $station->stream()->update(['status' => 'stopped']);

        $this->dispatch('notify',
            message: $ok ? __('Container gestoppt.') : __('Stop fehlgeschlagen – siehe Logs.'),
            type: $ok ? 'success' : 'info',
        );
    }

    public function restartStation(ContainerServiceInterface $containers): void
    {
        $station = $this->requireStation();

        if (! $containers->isConfigured()) {
            $this->dispatch('notify', message: __('The container service is not configured.'), type: 'info');

            return;
        }

        $ok = $containers->restartStationContainer($station);

        if ($ok) {
            $station->stream()->update(['status' => 'running', 'last_started_at' => now()]);
        }

        $this->dispatch('notify',
            message: $ok ? __('Container neu gestartet (Script neu geladen).') : __('Neustart fehlgeschlagen – siehe Logs.'),
            type: $ok ? 'success' : 'info',
        );
    }

    public function skipTrack(LiquidsoapCommandService $commands, LiquidsoapStateService $state): void
    {
        $station = $this->requireStation();

        // Cursor auf den Track nach dem laufenden zurückspulen, sonst springt der Skip
        // durch den vorausgeeilten prefetch-Cursor über mehrere Tracks.
        $state->prepareSkip($station);

        $ok = $commands->skip($station);

        $this->dispatch('notify',
            message: $ok ? __('Nächster Track ...') : __('Skip fehlgeschlagen – läuft der Container?'),
            type: $ok ? 'success' : 'info',
        );
    }

    private function requireStation(): Station
    {
        return auth()->user()->currentStation()
            ?? abort(403, 'Keine Station ausgewählt.');
    }

    public function render(PlaylistProjectionService $projection, IcecastStatusService $icecast)
    {
        /** @var Station|null $station */
        $station = auth()->user()->currentStation();

        $state = null;
        $onAir = false;
        $nowPlayingTitle = null;
        $nowPlayingArtist = null;
        $nowPlayingSourceType = 'music';
        $nowPlayingDuration = null;
        $progressPercent = 0;
        $elapsedSeconds = 0;
        $trackPosition = 0; // 1-basierte Position des laufenden Tracks in seinem Rundown
        $trackTotal = 0;    // Anzahl Tracks im Rundown des laufenden Tracks
        $playlist = collect(); // projizierte, durchgehende Tages-Playlist
        $playlistCount = 0;
        $mediaCount = 0;
        $stream = null;
        $internalStreamUrl = null;
        $listeners = null;

        if ($station) {
            $playlistCount = $station->playlists()->count();
            $mediaCount = $station->mediaFiles()->count();
            $stream = $station->stream;

            $state = LiquidsoapState::where('station_id', $station->id)
                ->with([
                    'nowPlayingItem.generatedPlaylist',
                ])
                ->first();

            if ($state) {
                // Player aus dem denormalisierten Snapshot – überlebt das Löschen des
                // Items (z. B. Rundown-Neugenerierung während des Sendens). Während einer
                // Live-Übernahme zeigt stattdessen der Live-Kasten den Status.
                if (! $state->live_active && $state->now_playing_title && $state->now_playing_started_at) {
                    $nowPlayingSourceType = $state->now_playing_source_type ?? 'music';
                    $nowPlayingDuration = $state->now_playing_duration_seconds;
                    $elapsedSeconds = (int) $state->now_playing_started_at->diffInSeconds(now());

                    // Track gilt als beendet, wenn die Sendezeit seine Dauer deutlich
                    // überschreitet und kein neuer on_metadata-Callback mehr kam (z. B.
                    // Stille bei einer Programm-Lücke). Dann nicht weiter als „läuft"
                    // anzeigen – sonst friert der Player auf dem alten Track ein und der
                    // Zähler läuft über die Dauer hinaus. Adbreaks haben auf laut.fm eine
                    // variable Echtdauer und werden hier ausgenommen.
                    $hasEnded = $nowPlayingSourceType !== 'adbreak'
                        && $nowPlayingDuration !== null
                        && $elapsedSeconds > $nowPlayingDuration + self::NOW_PLAYING_STALE_GRACE_SECONDS;

                    if ($hasEnded) {
                        $nowPlayingSourceType = 'music';
                        $nowPlayingDuration = null;
                        $elapsedSeconds = 0;
                    } else {
                        $onAir = true;
                        $nowPlayingTitle = $state->now_playing_title;
                        $nowPlayingArtist = $state->now_playing_artist;
                        $duration = max(1, $nowPlayingDuration ?? 1);
                        $progressPercent = min(100, (int) round($elapsedSeconds / $duration * 100));
                    }
                }

                // Position des laufenden Tracks in seinem Rundown (nur solange das Item
                // noch existiert – nach einer Neugenerierung entfällt die Positionsanzeige).
                if ($state->nowPlayingItem?->generatedPlaylist) {
                    $trackPosition = $state->nowPlayingItem->position + 1;
                    $trackTotal = $state->nowPlayingItem->generatedPlaylist->items()->count();
                }
            }

            $playlist = $projection->project($station);

            // Listener count for the station's own Icecast. The service caches it, so the
            // dashboard's wire:poll does not turn into one request per tab every ten seconds.
            if ($output = $station->internalOutput()) {
                $internalStreamUrl = $station->internalStreamUrl($output);
                $listeners = $icecast->listeners($station);
            }
        }

        return view('livewire.dashboard', [
            'station' => $station,
            'state' => $state,
            'onAir' => $onAir,
            'nowPlayingTitle' => $nowPlayingTitle,
            'nowPlayingArtist' => $nowPlayingArtist,
            'nowPlayingSourceType' => $nowPlayingSourceType,
            'nowPlayingDuration' => $nowPlayingDuration,
            'progressPercent' => $progressPercent,
            'elapsedSeconds' => $elapsedSeconds,
            'trackPosition' => $trackPosition,
            'trackTotal' => $trackTotal,
            'playlist' => $playlist,
            'playlistCount' => $playlistCount,
            'mediaCount' => $mediaCount,
            'stream' => $stream,
            'internalStreamUrl' => $internalStreamUrl,
            'listeners' => $listeners,
            'liveStream' => $station?->liveStreamCredentials(),
            'liveActive' => (bool) ($state?->live_active),
            'liveTitle' => $state?->live_title,
            'liveArtist' => $state?->live_artist,
            'liveStartedAt' => $state?->live_started_at,
        ])->layout('layouts.app');
    }
}
