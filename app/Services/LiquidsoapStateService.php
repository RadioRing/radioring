<?php

namespace App\Services;

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\StationLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LiquidsoapStateService
{
    /**
     * Wie oft eine Transaktion bei einem Deadlock/Serialisierungsfehler (40001/1213)
     * automatisch wiederholt wird. Mehrere Schreiber konkurrieren um dieselbe
     * liquidsoap_states-Zeile (Pull inkl. Prefetch, Now-Playing-Callback, Hard-Start,
     * Rundown-Generierung) – ein Deadlock ist dort normal und per Retry zu lösen.
     */
    private const TRANSACTION_ATTEMPTS = 5;

    /**
     * Gibt das nächste zu spielende Item für eine Station zurück.
     * Inkrementiert die Position atomar und wechselt den Rundown bei Bedarf.
     *
     * Drops fill music past a due fixed time and holds back hard fixed items until their time.
     *
     * @return GeneratedPlaylistItem|null null = kein Rundown → Silence
     */
    public function pullNextItem(Station $station): ?GeneratedPlaylistItem
    {
        return DB::transaction(function () use ($station) {
            /** @var LiquidsoapState $state */
            $state = LiquidsoapState::lockForUpdate()
                ->firstOrCreate(
                    ['station_id' => $station->id],
                    ['current_item_position' => 0]
                );

            // Aktuellen Rundown laden (oder den für die aktuelle Stunde suchen)
            $rundown = $this->resolveCurrentRundown($station, $state);

            if (! $rundown) {
                $this->recordUnderrun($station, $state);

                return null;
            }

            // Item an aktueller Position holen
            $item = $rundown->items()->where('position', $state->current_item_position)->first();

            if ($item) {
                $item = $this->skipFillPastFixedTime($station, $state, $rundown, $item);
            }

            if (! $item) {
                // Rundown exhausted or its remaining fill skipped: advance to the next one.
                $rundown = $this->advanceToNextRundown($station, $state, $rundown);

                $item = $rundown?->items()->where('position', $state->current_item_position)->first();

                if ($item) {
                    $item = $this->skipFillPastFixedTime($station, $state, $rundown, $item);
                }

                if (! $item) {
                    $this->recordUnderrun($station, $state);

                    return null;
                }
            }

            // Never queue a hard fixed item early, the cut would flush it.
            if ($item->isHardFixed() && $item->fixed_at->isFuture()) {
                $state->update([
                    'current_rundown_id' => $rundown->id,
                    'current_item_position' => $item->position,
                ]);
                $this->recordUnderrun($station, $state);

                return null;
            }

            $state->update([
                // Always store the rundown so the now-playing callback can match the track.
                'current_rundown_id' => $rundown->id,
                'current_item_position' => $item->position + 1,
                'last_pulled_at' => now(),
                ...$this->underrunCleared($state),
            ]);

            return $item;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Skips the rest of a fill block when its next track would start after the next fixed time.
     *
     * Corrects drift against the plan. Only fill tracks are skipped; the fixed time may also
     * be the first item of the next hour.
     *
     * @return GeneratedPlaylistItem|null item to hand out, null when the rundown is used up
     */
    private function skipFillPastFixedTime(Station $station, LiquidsoapState $state, GeneratedPlaylist $rundown, GeneratedPlaylistItem $item): ?GeneratedPlaylistItem
    {
        if ($item->source_type !== 'resolved_fill') {
            return $item;
        }

        $following = $rundown->items()->where('position', '>', $item->position)->get();
        $fixed = $following->first(fn (GeneratedPlaylistItem $i): bool => $i->fixed_at !== null);

        if ($fixed) {
            $between = $following->filter(fn (GeneratedPlaylistItem $i): bool => $i->position < $fixed->position);
        } else {
            $fixed = $this->openingFixedItemAfter($station, $rundown);

            if (! $fixed) {
                return $item;
            }

            $between = $following;
        }

        // Non-fill items in between always play.
        $stillToPlay = $between->where('source_type', '!=', 'resolved_fill')->sum('duration_seconds');
        $latestStart = $fixed->fixed_at->copy()->subSeconds($stillToPlay);

        if ($this->expectedStartOf($state, $rundown, $item)->lt($latestStart)) {
            return $item;
        }

        // Continue with the first non-fill item after the block.
        $next = $between->first(fn (GeneratedPlaylistItem $i): bool => $i->source_type !== 'resolved_fill');

        if (! $next && $fixed->generated_playlist_id === $rundown->id) {
            $next = $fixed;
        }

        $skippedIds = $between
            ->filter(fn (GeneratedPlaylistItem $i): bool => $i->source_type === 'resolved_fill' && ($next === null || $i->position < $next->position))
            ->push($item)
            ->modelKeys();

        $newlySkipped = GeneratedPlaylistItem::whereKey($skippedIds)->whereNull('skipped_at')->update(['skipped_at' => now()]);

        if ($newlySkipped > 0) {
            Log::info("Station {$station->slug}: fixed time {$fixed->fixed_at->format('H:i:s')} is due, skipped {$newlySkipped} fill track(s) of rundown #{$rundown->id}.");
        }

        return $next;
    }

    /** First item of the following rundown, if it has a fixed time. */
    private function openingFixedItemAfter(Station $station, GeneratedPlaylist $rundown): ?GeneratedPlaylistItem
    {
        $next = GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')
            ->where(function ($query) use ($rundown) {
                $query->where('broadcast_date', '>', $rundown->broadcast_date)
                    ->orWhere(function ($q) use ($rundown) {
                        $q->where('broadcast_date', $rundown->broadcast_date)
                            ->where('broadcast_hour', '>', $rundown->broadcast_hour);
                    });
            })
            ->orderBy('broadcast_date')
            ->orderBy('broadcast_hour')
            ->with('firstItem')
            ->first();

        $first = $next?->firstItem;

        return $first?->fixed_at !== null ? $first : null;
    }

    /**
     * Expected airtime of the item: end of the track on air plus the queued items (prefetch).
     * Falls back to now without a usable on-air snapshot.
     */
    private function expectedStartOf(LiquidsoapState $state, GeneratedPlaylist $rundown, GeneratedPlaylistItem $item): CarbonInterface
    {
        $onAir = $state->nowPlayingItem;
        $endsAt = $state->nowPlayingEndsAt();

        if (! $onAir || ! $endsAt || $state->nowPlayingHasEnded()) {
            return now();
        }

        $endsAt = $endsAt->max(now());

        if ($onAir->generated_playlist_id === $rundown->id) {
            if ($onAir->position >= $item->position) {
                return now();
            }

            $queued = $rundown->items()
                ->where('position', '>', $onAir->position)
                ->where('position', '<', $item->position)
                ->whereNull('skipped_at')
                ->sum('duration_seconds');
        } else {
            // Previous hour still on air: its remaining items are queued first.
            $queued = GeneratedPlaylistItem::where('generated_playlist_id', $onAir->generated_playlist_id)
                ->where('position', '>', $onAir->position)
                ->whereNull('skipped_at')
                ->sum('duration_seconds')
                + $rundown->items()
                    ->where('position', '<', $item->position)
                    ->whereNull('skipped_at')
                    ->sum('duration_seconds');
        }

        return $endsAt->copy()->addSeconds((int) $queued);
    }

    /**
     * Notes that this pull had nothing to hand out.
     *
     * That on its own is NOT an incident: with prefetch=3 the cursor runs minutes ahead of
     * the airplay, so it runs dry long before the listener hears anything. Only the start
     * time is written here; whether the station is audibly silent is decided by
     * LiquidsoapState::underrunSeconds(), which waits for the on-air snapshot to expire.
     * The protocol line follows that same verdict, once per episode - the container pulls
     * every second.
     */
    private function recordUnderrun(Station $station, LiquidsoapState $state): void
    {
        $state->update([
            'last_pulled_at' => now(),
            'underrun_started_at' => $state->underrun_started_at ?? now(),
        ]);

        if ($state->underrun_logged_at !== null) {
            return;
        }

        $seconds = $state->underrunSeconds();

        if ($seconds === null) {
            return;
        }

        $silenceSince = now()->copy()->subSeconds($seconds);

        $state->update(['underrun_logged_at' => now()]);

        StationLog::create([
            'station_id' => $station->id,
            'event' => StationLog::EVENT_UNDERRUN,
            'generated_playlist_id' => $state->current_rundown_id,
            'message' => $station->emergencyItems()->exists()
                ? __('Programme underrun: nothing left to play since :time, the emergency loop took over.', [
                    'time' => $silenceSince->format('H:i:s'),
                ])
                : __('Programme underrun: nothing left to play since :time, the station is sending silence.', [
                    'time' => $silenceSince->format('H:i:s'),
                ]),
            'occurred_at' => $silenceSince,
        ]);
    }

    /**
     * Fields that end a running underrun, to be spread into the state update that hands
     * out an item. Empty when there is no underrun, so a normal pull writes nothing extra.
     *
     * @return array{underrun_started_at?: null, underrun_logged_at?: null}
     */
    private function underrunCleared(LiquidsoapState $state): array
    {
        if ($state->underrun_started_at === null && $state->underrun_logged_at === null) {
            return [];
        }

        return ['underrun_started_at' => null, 'underrun_logged_at' => null];
    }

    /**
     * Speichert den aktuell spielenden Track (Callback von Liquidsoap on_metadata).
     */
    public function setNowPlaying(Station $station, ?GeneratedPlaylistItem $item): void
    {
        // Interpret aus der Mediendatei des Items (für den denormalisierten Snapshot).
        $artist = $item?->mediaFile?->artist;

        $result = DB::transaction(function () use ($station, $item, $artist) {
            $state = LiquidsoapState::firstOrCreate(['station_id' => $station->id]);

            // Vorheriger Zustand (für die Übergangs-Erkennung live → playlist).
            $wasLive = (bool) $state->live_active;
            $wasEmergency = $state->onEmergency();

            // Doppelmeldung DESSELBEN Tracks: Liquidsoap meldet denselben Track manchmal
            // erneut (z. B. beim Wieder-Anlaufen der Playlist nach einem Container-Neustart).
            // Generierte Items haben pro Position eine eindeutige ID – gleiche ID heißt also
            // „derselbe Track nochmal", nicht ein echter Wiederholungs-Play. Dann den Snapshot
            // (inkl. Startzeit) NICHT zurücksetzen und KEINE zweite Protokollzeile schreiben.
            if ($item !== null && ! $wasLive && $state->now_playing_item_id === $item->id) {
                return ['duplicate' => true, 'wasLive' => false];
            }

            // Denormalisierter Snapshot: bleibt erhalten, falls das Item später gelöscht
            // wird (Rundown-Neugenerierung während des Sendens) – so zeigt der Player
            // weiterhin den real laufenden Track, bis der nächste Track-Callback kommt.
            $state->update([
                'now_playing_item_id' => $item?->id,
                'now_playing_title' => $item?->title,
                'now_playing_artist' => $artist,
                'now_playing_source_type' => $item?->source_type,
                'now_playing_duration_seconds' => $item?->duration_seconds,
                'now_playing_started_at' => $item ? now() : null,
                // Ein Track auf Sendung beendet eine offene Underrun-Episode: die Station
                // ist hörbar wieder da. Ohne das bliebe die Warnung stehen, solange der
                // Cursor trocken läuft - obwohl der Prefetch-Puffer noch spielt.
                'underrun_started_at' => $item ? null : $state->underrun_started_at,
                'underrun_logged_at' => $item ? null : $state->underrun_logged_at,
                // Ein regulärer (oder leerer) Track bedeutet: keine Live-Übernahme mehr.
                'live_active' => false,
                'live_title' => null,
                'live_artist' => null,
                'live_started_at' => null,
            ]);

            // 'played' anhand des ECHTEN Airplays setzen: sobald now_playing auf einem
            // Rundown steht, gelten alle chronologisch früheren ready-Rundowns als gespielt.
            if ($item) {
                $rundown = GeneratedPlaylist::find($item->generated_playlist_id);

                if ($rundown) {
                    GeneratedPlaylist::where('station_id', $station->id)
                        ->where('status', 'ready')
                        ->where('id', '!=', $rundown->id)
                        ->where(function ($query) use ($rundown) {
                            $query->where('broadcast_date', '<', $rundown->broadcast_date)
                                ->orWhere(function ($q) use ($rundown) {
                                    $q->where('broadcast_date', $rundown->broadcast_date)
                                        ->where('broadcast_hour', '<', $rundown->broadcast_hour);
                                });
                        })
                        ->update(['status' => 'played']);
                }
            }

            return ['duplicate' => false, 'wasLive' => $wasLive, 'wasEmergency' => $wasEmergency];
        }, self::TRANSACTION_ATTEMPTS);

        // Doppelmeldung → kein Protokoll, kein Snapshot-Reset.
        if ($result['duplicate']) {
            return;
        }

        $wasLive = $result['wasLive'];

        if ($result['wasEmergency'] && $item) {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_EMERGENCY_STOPPED,
                'message' => __('The programme is back, the emergency loop is off air.'),
                'occurred_at' => now(),
            ]);
        }

        // Protokoll: Wechsel von Live zurück auf das reguläre Programm festhalten.
        if ($wasLive) {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_LIVE_STOPPED,
                'message' => __('Live-Übernahme beendet – zurück zum Programm.'),
                'occurred_at' => now(),
            ]);
        }

        // Protokoll: jeden real ausgespielten Track festhalten. Der Callback feuert pro
        // Track-Übergang der radio-Source (= echtes Airplay), daher ist jeder Aufruf mit
        // Item eine eigene Protokollzeile. Aufeinanderfolgende gleiche Titel bleiben
        // absichtlich sichtbar – sie verraten Probleme (z. B. Container-Neustart, der
        // denselben Track erneut startet).
        if ($item) {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_TRACK,
                'source' => 'playlist',
                'media_file_id' => $item->media_file_id,
                'generated_playlist_item_id' => $item->id,
                'title' => $item->title,
                'artist' => $artist,
                'source_type' => $item->source_type,
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * Airplay whose item can no longer be resolved.
     *
     * The rundown was regenerated while this track was already in Liquidsoap's prefetch
     * queue, so its annotated item id points at a row that no longer exists. The container
     * is demonstrably on air, and it tells us what it plays, so keep the snapshot alive
     * from that metadata. Reporting silence here is what used to freeze the dashboard on
     * "NO PLAYOUT" until the prefetch queue had drained.
     *
     * There is no item to hang a duration or a rundown position on, so both stay empty
     * until the next track pulled after the regeneration reports in with a fresh id.
     */
    public function setNowPlayingUnidentified(Station $station, ?string $title, ?string $artist): void
    {
        $result = DB::transaction(function () use ($station, $title, $artist) {
            $state = LiquidsoapState::firstOrCreate(['station_id' => $station->id]);

            $wasLive = (bool) $state->live_active;

            // Same track reported twice: keep the snapshot, above all its start time.
            if (! $wasLive
                && $state->now_playing_item_id === null
                && $state->now_playing_title === $title
                && $state->now_playing_artist === $artist
            ) {
                return ['duplicate' => true, 'wasLive' => false];
            }

            $state->update([
                'now_playing_item_id' => null,
                'now_playing_title' => $title,
                'now_playing_artist' => $artist,
                'now_playing_source_type' => null,
                'now_playing_duration_seconds' => null,
                'now_playing_started_at' => now(),
                'live_active' => false,
                'live_title' => null,
                'live_artist' => null,
                'live_started_at' => null,
            ]);

            return ['duplicate' => false, 'wasLive' => $wasLive];
        }, self::TRANSACTION_ATTEMPTS);

        if ($result['duplicate']) {
            return;
        }

        if ($result['wasLive']) {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_LIVE_STOPPED,
                'message' => __('Live-Übernahme beendet – zurück zum Programm.'),
                'occurred_at' => now(),
            ]);
        }

        // Log it like any other track: without this the protocol has a hole around every
        // regeneration, exactly where an operator goes looking for one.
        StationLog::create([
            'station_id' => $station->id,
            'event' => StationLog::EVENT_TRACK,
            'source' => 'playlist',
            'title' => $title,
            'artist' => $artist,
            'occurred_at' => now(),
        ]);
    }

    /**
     * The emergency loop is on air: neither live nor the programme was available.
     *
     * Reported like any other track by on_metadata, but marked with radioring_source, which
     * is what keeps it out of the live takeover branch. The underrun fields stay untouched:
     * the programme is still dry, and the dashboard has to keep saying so.
     */
    public function setNowPlayingEmergency(Station $station, ?MediaFile $file, ?string $title, ?string $artist): void
    {
        $wasEmergency = DB::transaction(function () use ($station, $file, $title, $artist) {
            $state = LiquidsoapState::firstOrCreate(['station_id' => $station->id]);

            $wasEmergency = $state->onEmergency();

            $state->update([
                'now_playing_item_id' => null,
                'now_playing_title' => $file?->title ?: ($title ?: __('Emergency loop')),
                'now_playing_artist' => $file?->artist ?: $artist,
                'now_playing_source_type' => 'emergency',
                'now_playing_duration_seconds' => $file?->duration_seconds,
                'now_playing_started_at' => now(),
                'live_active' => false,
                'live_title' => null,
                'live_artist' => null,
                'live_started_at' => null,
            ]);

            return $wasEmergency;
        }, self::TRANSACTION_ATTEMPTS);

        // Once per episode, not per track: a loop running all night would otherwise fill
        // the protocol on its own.
        if (! $wasEmergency) {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_EMERGENCY_STARTED,
                'message' => __('The emergency loop took over: neither the programme nor a live takeover was available.'),
                'occurred_at' => now(),
            ]);
        }
    }

    /** Stamps the moment the container last fetched the emergency manifest. */
    public function markEmergencySynced(Station $station): void
    {
        DB::transaction(function () use ($station) {
            LiquidsoapState::firstOrCreate(['station_id' => $station->id])
                ->update(['emergency_synced_at' => now()]);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Speichert eine aktive Live-Übernahme (input.harbor) inkl. eingehender Metadaten.
     * Erkannt daran, dass der on_metadata-Callback Metadaten OHNE radioring_item_id
     * liefert (unsere eigenen Tracks tragen immer eine Item-ID).
     */
    public function setLive(Station $station, ?string $title, ?string $artist): void
    {
        $justStarted = DB::transaction(function () use ($station, $title, $artist) {
            $state = LiquidsoapState::firstOrCreate(['station_id' => $station->id]);

            $justStarted = ! $state->live_active;

            $update = [
                'live_active' => true,
                'live_title' => $title,
                'live_artist' => $artist,
                // Während der Live-Übernahme zeigt der Player keinen Rundown-Track. Den
                // gesamten Snapshot leeren (nicht nur die ID), sonst läuft die Abspielzeit
                // des letzten Programm-Tracks im Player „virtuell" weiter, während live sendet.
                'now_playing_item_id' => null,
                'now_playing_title' => null,
                'now_playing_artist' => null,
                'now_playing_source_type' => null,
                'now_playing_duration_seconds' => null,
                'now_playing_started_at' => null,
            ];

            // Startzeitpunkt nur beim Übergang nach „live" setzen, nicht bei jedem Update.
            if ($justStarted) {
                $update['live_started_at'] = now();
            }

            $state->update($update);

            return $justStarted;
        }, self::TRANSACTION_ATTEMPTS);

        // Protokoll: Beginn einer Live-Übernahme (nur beim Übergang, nicht bei jedem Update).
        if ($justStarted) {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_LIVE_STARTED,
                'message' => __('Live-Übernahme gestartet.'),
                'occurred_at' => now(),
            ]);
        }

        // Protokoll für den Live-Track. Der Harbor-Callback kann dieselben Metadaten
        // mehrfach liefern – daher nur protokollieren, wenn sich der Titel gegenüber
        // dem letzten Live-Track-Eintrag dieser Station geändert hat.
        $last = StationLog::where('station_id', $station->id)
            ->where('event', StationLog::EVENT_TRACK)
            ->latest('occurred_at')
            ->latest('id')
            ->first();

        $isRepeat = $last
            && $last->source === 'live'
            && $last->title === $title
            && $last->artist === $artist;

        if (! $isRepeat) {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_TRACK,
                'source' => 'live',
                'title' => $title,
                'artist' => $artist,
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * Setzt den Live-Status anhand der harbor-Verbindung selbst (on_connect/on_disconnect),
     * unabhängig von eingehenden Metadaten. Nötig, weil setLive() nur feuert, wenn der
     * Encoder Metadaten ändert – bleibt der Titel lange gleich, sah RadioRing die laufende
     * Live-Sendung sonst gar nicht (bzw. erst verspätet).
     */
    public function setLiveConnected(Station $station, bool $connected): void
    {
        $changed = DB::transaction(function () use ($station, $connected) {
            $state = LiquidsoapState::firstOrCreate(['station_id' => $station->id]);
            $wasLive = (bool) $state->live_active;

            if ($connected) {
                if ($wasLive) {
                    return null;
                }

                // Verbindung steht, Metadaten evtl. noch unbekannt – setLive() ergänzt
                // Titel/Interpret, sobald der Encoder welche schickt. Snapshot komplett
                // leeren, damit der Player nicht „virtuell" weiterläuft, während live sendet.
                $state->update([
                    'live_active' => true,
                    'live_started_at' => now(),
                    'now_playing_item_id' => null,
                    'now_playing_title' => null,
                    'now_playing_artist' => null,
                    'now_playing_source_type' => null,
                    'now_playing_duration_seconds' => null,
                    'now_playing_started_at' => null,
                ]);

                return 'started';
            }

            if (! $wasLive) {
                return null;
            }

            $state->update([
                'live_active' => false,
                'live_title' => null,
                'live_artist' => null,
                'live_started_at' => null,
            ]);

            return 'stopped';
        }, self::TRANSACTION_ATTEMPTS);

        if ($changed === 'started') {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_LIVE_STARTED,
                'message' => __('Live-Übernahme gestartet.'),
                'occurred_at' => now(),
            ]);
        } elseif ($changed === 'stopped') {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_LIVE_STOPPED,
                'message' => __('Live-Übernahme beendet – zurück zum Programm.'),
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * Next hard fixed item within the announce window, unless already announced.
     *
     * The container gets the cut with a lead time and times the fade itself, so the cut
     * lands exactly on the fixed time.
     */
    public function upcomingHardStart(Station $station): ?GeneratedPlaylistItem
    {
        $candidate = $this->hardFixedItemsBetween($station, now(), now()->addSeconds(self::HARD_START_ANNOUNCE_SECONDS))
            ->first(fn (GeneratedPlaylistItem $item): bool => $item->fixed_at->isFuture());

        if (! $candidate) {
            return null;
        }

        $state = LiquidsoapState::where('station_id', $station->id)->first();

        if ($state?->committed_hard_time?->equalTo($candidate->fixed_at)) {
            return null;
        }

        return $candidate;
    }

    /** Seconds until the item's hard fixed time. */
    public function secondsUntilStart(GeneratedPlaylistItem $item): float
    {
        return max(0.0, now()->floatDiffInSeconds($item->fixed_at, absolute: false));
    }

    /**
     * Records an announced hard start without moving the cursor.
     *
     * Moving it early would queue the item and the cut would flush it; resolveCurrentRundown
     * jumps to it after the cut.
     */
    public function announceHardStart(Station $station, GeneratedPlaylistItem $hard): void
    {
        DB::transaction(function () use ($station, $hard) {
            LiquidsoapState::updateOrCreate(
                ['station_id' => $station->id],
                ['committed_hard_time' => $hard->fixed_at],
            );
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Due hard fixed item the airplay has not reached yet, for an immediate cut when the
     * announcement was missed.
     */
    public function pendingHardStart(Station $station): ?GeneratedPlaylistItem
    {
        $hard = $this->dueHardItem($station);

        if (! $hard) {
            return null;
        }

        $state = LiquidsoapState::where('station_id', $station->id)
            ->with('nowPlayingItem')
            ->first();

        // Make sure cut only happens once.
        if ($state?->committed_hard_time?->equalTo($hard->fixed_at)) {
            return null;
        }

        // Judge by airplay, not the cursor (prefetch runs ahead). An item that started
        // before its fixed time counts as not started and is cut to again.
        $onAir = $state?->nowPlayingItem;

        $isOnAir = $onAir !== null
            && $onAir->generated_playlist_id === $hard->generated_playlist_id
            && $onAir->position >= $hard->position;

        $startedEarly = $isOnAir
            && $state->now_playing_started_at
            && $state->now_playing_started_at->lt($hard->fixed_at);

        if ($isOnAir && ! $startedEarly) {
            return null;
        }

        return $hard;
    }

    /** Moves the cursor to the hard item; the following skip makes Liquidsoap pull it. */
    public function commitHardStart(Station $station, GeneratedPlaylistItem $hard): void
    {
        DB::transaction(function () use ($station, $hard) {
            LiquidsoapState::updateOrCreate(
                ['station_id' => $station->id],
                [
                    'current_rundown_id' => $hard->generated_playlist_id,
                    'current_item_position' => $hard->position,
                    'committed_hard_time' => $hard->fixed_at,
                ],
            );
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Bereitet einen manuellen Skip vor: setzt den Pull-Cursor auf den Track NACH dem
     * aktuell hörbaren (now_playing). Der flush_and_skip-Befehl leert nur den
     * prefetch-Puffer von Liquidsoap – ohne diesen Cursor-Rückspulvorgang läge der
     * Cursor durch prefetch noch mehrere Tracks voraus, und der Skip überspränge sie
     * alle statt nur den laufenden Track.
     */
    public function prepareSkip(Station $station): void
    {
        DB::transaction(function () use ($station) {
            $state = LiquidsoapState::with('nowPlayingItem')
                ->where('station_id', $station->id)
                ->first();

            if (! $state) {
                return;
            }

            $nowPlaying = $state->nowPlayingItem;

            if ($nowPlaying) {
                $state->update([
                    'current_rundown_id' => $nowPlaying->generated_playlist_id,
                    'current_item_position' => $nowPlaying->position + 1,
                ]);

                return;
            }

            // Kein now_playing-Item (typisch direkt nach einer Neu-Generierung: die
            // alten Items wurden gelöscht, der FK genullt, der real laufende Track
            // existiert nicht mehr im Rundown). Der Pull-Cursor ist durch den prefetch
            // evtl. schon vorgelaufen – ohne Rückspulen würde der flush_and_skip mehrere
            // Titel überspringen. Auf den Anfang des aktuellen Rundowns zurücksetzen
            // (denselben Startpunkt, den auch der Generator setzt), damit der Skip auf
            // dessen ersten Track landet.
            $target = $this->findRundownForNow($station);

            if ($target) {
                $state->update([
                    'current_rundown_id' => $target->id,
                    'current_item_position' => 0,
                ]);
            }
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Setzt den Pull-Cursor auf den tatsächlich zuletzt gesendeten Track (now_playing)
     * zurück. Wird beim (Neu-)Start des Containers über /connect aufgerufen:
     * Liquidsoap verliert dann seinen prefetch-Puffer, der DB-Cursor ist diesem aber
     * bis zu prefetch Tracks vorausgeeilt – ohne Reset würde der Stream nach dem
     * Neustart mehrere Tracks überspringen.
     */
    public function resumeFromAirplay(Station $station): void
    {
        DB::transaction(function () use ($station) {
            $state = LiquidsoapState::with('nowPlayingItem.generatedPlaylist')
                ->firstOrCreate(
                    ['station_id' => $station->id],
                    ['current_item_position' => 0],
                );

            $nowPlaying = $state->nowPlayingItem;
            $rundown = $nowPlaying?->generatedPlaylist;

            // 1. Kurzer Neustart: der zuletzt gesendete Track würde laut Wanduhr noch
            //    laufen → exakt dort fortsetzen (keine Lücke, kein Sprung, kein Drop).
            if ($rundown
                && $rundown->status === 'ready'
                && $rundown->broadcast_date->isSameDay(today())
                && $state->now_playing_started_at
                && now()->lte($state->now_playing_started_at->copy()->addSeconds((int) ($nowPlaying->duration_seconds ?? 0)))
            ) {
                $state->update([
                    'current_rundown_id' => $rundown->id,
                    'current_item_position' => $nowPlaying->position,
                ]);

                return;
            }

            // 2. Längere Downtime oder Kaltstart: den Rundown der aktuellen Stunde laden.
            $target = $this->findRundownForNow($station);

            if (! $target) {
                $state->update([
                    'current_rundown_id' => null,
                    'current_item_position' => 0,
                    'now_playing_item_id' => null,
                    'now_playing_title' => null,
                    'now_playing_source_type' => null,
                    'now_playing_duration_seconds' => null,
                    'now_playing_started_at' => null,
                ]);

                return;
            }

            // Wurde der Rundown ERST während seiner laufenden Stunde (neu) generiert, ist
            // davon noch nichts gesendet worden → von vorne (Position 0). Wurde er regulär
            // vorab geplant (Nacht-Job / :55-Preload), an der Wanduhr ausrichten: bereits
            // fällige Items überspringen, statt die schon vergangene Stunde zu wiederholen.
            $hourStart = today()->setTime($target->broadcast_hour, 0, 0);
            $freshlyGenerated = $target->generated_at && $target->generated_at->gte($hourStart);

            if ($freshlyGenerated) {
                $position = 0;
            } else {
                // reorder() verwirft das Standard-orderBy('position') der Relation – sonst
                // gewinnt die Positions-Sortierung und first() liefert immer Position 0.
                $dueItem = $target->items()
                    ->whereNotNull('absolute_broadcast_at')
                    ->where('absolute_broadcast_at', '<=', now())
                    ->reorder('absolute_broadcast_at', 'desc')
                    ->first();

                $position = $dueItem?->position ?? 0;
            }

            $state->update([
                'current_rundown_id' => $target->id,
                'current_item_position' => $position,
                'now_playing_item_id' => null,
                'now_playing_title' => null,
                'now_playing_source_type' => null,
                'now_playing_duration_seconds' => null,
                'now_playing_started_at' => null,
            ]);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * How many rundowns past the current one the pull horizon may reach into. Two hours of
     * programme are enough for any prefetch depth and keep the query small.
     */
    private const HORIZON_RUNDOWNS = 2;

    /**
     * The items the pull cursor is about to hand out, in broadcast order, across rundown
     * boundaries.
     *
     * @return Collection<int, GeneratedPlaylistItem>
     */
    public function upcomingItems(Station $station, int $limit): Collection
    {
        $state = LiquidsoapState::where('station_id', $station->id)->first();

        if (! $state || ! $state->current_rundown_id) {
            return collect();
        }

        $current = GeneratedPlaylist::find($state->current_rundown_id);

        if (! $current) {
            return collect();
        }

        // The relation orders by position, so this is the cursor and everything behind it.
        $items = $current->items()
            ->where('position', '>=', $state->current_item_position)
            ->limit($limit)
            ->get();

        if ($items->count() >= $limit) {
            return $items;
        }

        // The hour runs out inside the horizon: the following rundowns continue it. Taken
        // in broadcast order, the same order advanceToNextRundown walks.
        $following = GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')
            ->where(function ($query) use ($current) {
                $query->where('broadcast_date', '>', $current->broadcast_date)
                    ->orWhere(function ($q) use ($current) {
                        $q->where('broadcast_date', $current->broadcast_date)
                            ->where('broadcast_hour', '>', $current->broadcast_hour);
                    });
            })
            ->orderBy('broadcast_date')
            ->orderBy('broadcast_hour')
            ->limit(self::HORIZON_RUNDOWNS)
            ->get();

        foreach ($following as $rundown) {
            if ($items->count() >= $limit) {
                break;
            }

            $items = $items->concat($rundown->items()->limit($limit - $items->count())->get());
        }

        return $items;
    }

    /**
     * Liefert den aktuellen Rundown – entweder den gespeicherten oder
     * sucht den passenden für die aktuelle Stunde.
     */
    private function resolveCurrentRundown(Station $station, LiquidsoapState $state): ?GeneratedPlaylist
    {
        if ($state->current_rundown_id) {
            $current = GeneratedPlaylist::find($state->current_rundown_id);

            if ($current && $current->status !== 'played') {
                // Jump to a due hard fixed item the cursor has not reached yet. Only within
                // the enforcement window, otherwise late news would start at any minute.
                $hard = $this->dueHardItem($station);

                if ($hard && $this->cursorIsBefore($state, $current, $hard)) {
                    // Cursor only; 'played' follows the airplay in setNowPlaying.
                    $state->update([
                        'current_rundown_id' => $hard->generated_playlist_id,
                        'current_item_position' => $hard->position,
                    ]);

                    return $hard->generatedPlaylist;
                }

                return $current;
            }
        }

        // Rundown für aktuelle Stunde suchen
        return $this->findRundownForNow($station);
    }

    /**
     * How many rundowns past the exhausted cursor are considered at most when looking for
     * the one that is due. Two days of programme cover any realistic backlog and cap the
     * number of rows loaded at the same time.
     */
    private const ADVANCE_LOOKAHEAD = 48;

    /**
     * Moves on to the next rundown that is due, catching up on a backlog on the way.
     *
     * An hour that overruns starts the next one late and pushes the whole rest of the day
     * back. Without catching up that offset accumulates unchecked: at 13:55 the programme
     * would still be working off the 11:00 hour. So it is not the next rundown that is
     * picked but the LATEST one already due - skipped hours are dropped and the wall clock
     * is back in sync. If none is due yet (a gap in the schedule), the cursor stays put and
     * Liquidsoap gets silence until the scheduled hour.
     */
    private function advanceToNextRundown(Station $station, LiquidsoapState $state, GeneratedPlaylist $current): ?GeneratedPlaylist
    {
        // WICHTIG: hier NICHT 'played' setzen. Der Pull-Cursor eilt durch das
        // Prefetching dem echten Airplay voraus – würde man hier markieren, wäre ein
        // Rundown „played", obwohl noch Tracks daraus laufen. 'played' setzt
        // setNowPlaying anhand des tatsächlichen Airplays.

        // Following rundowns in broadcast order: same day later hour, or the next day.
        $candidates = GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')
            ->where(function ($query) use ($current) {
                $query->where('broadcast_date', '>', $current->broadcast_date)
                    ->orWhere(function ($q) use ($current) {
                        $q->where('broadcast_date', $current->broadcast_date)
                            ->where('broadcast_hour', '>', $current->broadcast_hour);
                    });
            })
            ->orderBy('broadcast_date')
            ->orderBy('broadcast_hour')
            ->with('firstItem')
            ->limit(self::ADVANCE_LOOKAHEAD)
            ->get();

        // The latest rundown whose broadcast time the wall clock has reached. Start times
        // increase monotonically, so the due ones form a prefix of the list.
        $next = $candidates->last(fn (GeneratedPlaylist $rundown): bool => $this->mayStartRundownNow($rundown));

        if (! $next) {
            return null;
        }

        // Skipping at least one hour means the programme is running behind: do not enter at
        // position 0 then (that would replay a long past hour from the top), but align with
        // the wall clock instead.
        $isCatchUp = $next->isNot($candidates->first());

        $state->update([
            'current_rundown_id' => $next->id,
            'current_item_position' => $isCatchUp ? $this->wallClockPosition($next) : 0,
        ]);

        if ($isCatchUp) {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_SCHEDULE_CATCH_UP,
                'generated_playlist_id' => $next->id,
                'message' => __('Program was running behind: caught up to :hour.', [
                    'hour' => sprintf('%02d:00', $next->broadcast_hour),
                ]),
                'occurred_at' => now(),
            ]);
        }

        return $next;
    }

    /**
     * The position inside a rundown the wall clock calls for: the last item whose planned
     * broadcast time has been reached. Without planned times (older rundowns) it stays at
     * position 0.
     */
    private function wallClockPosition(GeneratedPlaylist $rundown): int
    {
        // reorder() drops the relation's default orderBy('position') - otherwise the
        // position sorting wins and first() always returns position 0.
        $dueItem = $rundown->items()
            ->whereNotNull('absolute_broadcast_at')
            ->where('absolute_broadcast_at', '<=', now())
            ->reorder('absolute_broadcast_at', 'desc')
            ->first();

        return $dueItem?->position ?? 0;
    }

    /**
     * Vorlauf in Sekunden, mit dem ein Soft-Start-Rundown vor seiner vollen Stunde
     * angezogen werden darf, damit der Prefetch den ersten Track nahtlos zum
     * Stundenübergang ziehen kann. Hard-Starts bekommen keinen Vorlauf.
     */
    private const SOFT_ADVANCE_LEAD_SECONDS = 120;

    /**
     * Darf der angegebene Rundown laut Wanduhr jetzt schon angefahren werden?
     *
     * Schützt vor dem Vorziehen künftiger Rundowns über eine Programm-Lücke hinweg.
     * Soft starts get a short prefetch lead; an hour that starts hard is entered on the hour.
     */
    private function mayStartRundownNow(GeneratedPlaylist $rundown): bool
    {
        $lead = $rundown->startsHard() ? 0 : self::SOFT_ADVANCE_LEAD_SECONDS;

        return now()->gte($this->startOf($rundown)->subSeconds($lead));
    }

    /**
     * Window after a hard fixed time in which it is still enforced: one track overhang plus a
     * missed EnforceHardStarts run. Later the fixed time counts as missed.
     */
    private const HARD_START_WINDOW_SECONDS = 600;

    /**
     * Announce lead for a hard start: more than one enforce-hard-starts cadence (60 s), less
     * than two, so exactly one run announces it.
     */
    private const HARD_START_ANNOUNCE_SECONDS = 90;

    /** Latest hard fixed item inside the enforcement window. */
    private function dueHardItem(Station $station): ?GeneratedPlaylistItem
    {
        return $this->hardFixedItemsBetween($station, now()->subSeconds(self::HARD_START_WINDOW_SECONDS), now())->last();
    }

    /**
     * Hard fixed items between two moments, in time order. Limited to the touched hours, as
     * this runs on every pull.
     *
     * @return Collection<int, GeneratedPlaylistItem>
     */
    private function hardFixedItemsBetween(Station $station, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $hours = [];

        for ($hour = $from->copy()->startOfHour(); $hour->lte($to); $hour = $hour->copy()->addHour()) {
            $hours[] = $hour;
        }

        $rundownIds = GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')
            ->where(function ($query) use ($hours) {
                foreach ($hours as $hour) {
                    $query->orWhere(fn ($q) => $q
                        ->where('broadcast_date', $hour->copy()->startOfDay())
                        ->where('broadcast_hour', $hour->hour));
                }
            })
            ->pluck('id');

        if ($rundownIds->isEmpty()) {
            return collect();
        }

        return GeneratedPlaylistItem::whereIn('generated_playlist_id', $rundownIds)
            ->where('fixed_mode', 'hard')
            ->whereBetween('fixed_at', [$from, $to])
            ->orderBy('fixed_at')
            ->with('generatedPlaylist')
            ->get();
    }

    /** Is the cursor still in front of the item (earlier hour or earlier position)? */
    private function cursorIsBefore(LiquidsoapState $state, GeneratedPlaylist $current, GeneratedPlaylistItem $item): bool
    {
        if ($current->id === $item->generated_playlist_id) {
            return $state->current_item_position < $item->position;
        }

        return $this->startOf($current)->lt($this->startOf($item->generatedPlaylist));
    }

    /** Planned start of a rundown. */
    private function startOf(GeneratedPlaylist $rundown): CarbonInterface
    {
        return $rundown->broadcast_date->copy()->setTime($rundown->broadcast_hour, 0, 0);
    }

    /**
     * Findet den Rundown für die aktuelle Stunde (oder die letzte vergangene).
     */
    private function findRundownForNow(Station $station): ?GeneratedPlaylist
    {
        return GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')
            ->where('broadcast_date', today())
            ->where('broadcast_hour', now()->hour)
            ->first();
    }
}
