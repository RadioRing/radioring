<?php

namespace App\Services;

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Models\StationLog;
use Illuminate\Support\Facades\DB;

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
                $state->update(['last_pulled_at' => now()]);

                return null;
            }

            // Item an aktueller Position holen
            $item = $rundown->items()->where('position', $state->current_item_position)->first();

            if (! $item) {
                // Position erschöpft → nächsten Rundown suchen
                $rundown = $this->advanceToNextRundown($station, $state, $rundown);

                if (! $rundown) {
                    $state->update(['last_pulled_at' => now()]);

                    return null;
                }

                $item = $rundown->items()->where('position', 0)->first();

                if (! $item) {
                    $state->update(['last_pulled_at' => now()]);

                    return null;
                }

                $state->update([
                    'current_rundown_id' => $rundown->id,
                    'current_item_position' => 1,
                    'last_pulled_at' => now(),
                ]);
            } else {
                $state->update([
                    // current_rundown_id immer mitschreiben – sonst kann der
                    // Now-Playing-Callback den Track nicht zuordnen (bleibt null).
                    'current_rundown_id' => $rundown->id,
                    'current_item_position' => $state->current_item_position + 1,
                    'last_pulled_at' => now(),
                ]);
            }

            return $item;
        }, self::TRANSACTION_ATTEMPTS);
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

            return ['duplicate' => false, 'wasLive' => $wasLive];
        }, self::TRANSACTION_ATTEMPTS);

        // Doppelmeldung → kein Protokoll, kein Snapshot-Reset.
        if ($result['duplicate']) {
            return;
        }

        $wasLive = $result['wasLive'];

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
     * Liefert den Hard-Start-Rundown der aktuellen Stunde, FALLS der Pull-Cursor
     * noch nicht auf ihm steht – sonst null. Für den sample-genauen Stunden-Cut.
     */
    public function pendingHardStart(Station $station): ?GeneratedPlaylist
    {
        $hard = $this->findHardRundownForNow($station);

        if (! $hard) {
            return null;
        }

        $state = LiquidsoapState::where('station_id', $station->id)
            ->with('nowPlayingItem')
            ->first();

        // Entscheidend am ECHTEN Airplay (now_playing), NICHT am Pull-Cursor
        // (current_rundown_id): Der Cursor eilt durch prefetch=3 bis zu drei Tracks
        // voraus und steht zur vollen Stunde oft schon im Hard-Rundown, während
        // hörbar noch der Überhang der Vorstunde läuft. Würde man den Cursor prüfen,
        // bliebe der Cut aus und die Vorstunde liefe einfach weiter.
        if ($state?->nowPlayingItem?->generated_playlist_id === $hard->id) {
            return null;
        }

        return $hard;
    }

    /**
     * Setzt den Pull-Cursor auf den Hard-Rundown (Position 0). Der anschließende
     * skip-Befehl lässt Liquidsoap sofort /next ziehen → Track 0 des Hard-Rundowns.
     */
    public function commitHardStart(Station $station, GeneratedPlaylist $hard): void
    {
        DB::transaction(function () use ($station, $hard) {
            LiquidsoapState::updateOrCreate(
                ['station_id' => $station->id],
                ['current_rundown_id' => $hard->id, 'current_item_position' => 0],
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
     * Liefert den aktuellen Rundown – entweder den gespeicherten oder
     * sucht den passenden für die aktuelle Stunde.
     */
    private function resolveCurrentRundown(Station $station, LiquidsoapState $state): ?GeneratedPlaylist
    {
        if ($state->current_rundown_id) {
            $current = GeneratedPlaylist::find($state->current_rundown_id);

            if ($current && $current->status !== 'played') {
                // Hard-Start respektieren: Wenn für die JETZIGE Wanduhr-Stunde ein
                // anderer, fertiger Rundown mit start_mode=hard bereitsteht, wird
                // der Überhang am nächsten Track-Übergang abgeschnitten und auf den
                // neuen Rundown gewechselt. (soft / leere Stunde → weiterlaufen)
                $hardNow = $this->findHardRundownForNow($station);

                if ($hardNow && $hardNow->id !== $current->id) {
                    // Nur den Pull-Cursor umsetzen. 'played' wird NICHT hier gesetzt,
                    // sondern erst wenn now_playing tatsächlich auf den neuen Rundown
                    // wechselt (siehe setNowPlaying) – sonst läuft der Status dem Audio
                    // durch das Prefetching voraus.
                    $state->update([
                        'current_rundown_id' => $hardNow->id,
                        'current_item_position' => 0,
                    ]);

                    return $hardNow;
                }

                return $current;
            }
        }

        // Rundown für aktuelle Stunde suchen
        return $this->findRundownForNow($station);
    }

    /**
     * Wechselt zum nächsten verfügbaren Rundown (nächste Stunde oder folgende).
     */
    private function advanceToNextRundown(Station $station, LiquidsoapState $state, GeneratedPlaylist $current): ?GeneratedPlaylist
    {
        // WICHTIG: hier NICHT 'played' setzen. Der Pull-Cursor eilt durch das
        // Prefetching dem echten Airplay voraus – würde man hier markieren, wäre ein
        // Rundown „played", obwohl noch Tracks daraus laufen. 'played' setzt
        // setNowPlaying anhand des tatsächlichen Airplays.

        // Nächsten Rundown suchen: gleicher Tag spätere Stunde, oder Folgetag
        $next = GeneratedPlaylist::where('station_id', $station->id)
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
            ->with('playlist')
            ->first();

        // Programm-Lücke: Ist der nächste Rundown erst später geplant (z. B. läuft die
        // Stunde leer aus und der nächste belegte Slot ist erst Stunden später), darf er
        // NICHT vorgezogen werden – sonst startet etwa der 18-Uhr-Rundown schon um 16 Uhr.
        // Cursor bleibt dann auf dem erschöpften Rundown; Liquidsoap pollt /next
        // (retry_delay=1s) und bekommt bis zur geplanten Stunde Silence.
        if ($next && ! $this->mayStartRundownNow($next)) {
            return null;
        }

        if ($next) {
            $state->update([
                'current_rundown_id' => $next->id,
                'current_item_position' => 0,
            ]);
        }

        return $next;
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
     * Soft-Starts dürfen mit kleinem Prefetch-Vorlauf beginnen; Hard-Starts erst ab
     * der vollen Stunde (der sample-genaue Cut wird separat über EnforceHardStarts
     * erzwungen).
     */
    private function mayStartRundownNow(GeneratedPlaylist $rundown): bool
    {
        $start = $rundown->broadcast_date->copy()->setTime($rundown->broadcast_hour, 0, 0);

        $lead = $this->isHardStart($rundown) ? 0 : self::SOFT_ADVANCE_LEAD_SECONDS;

        return now()->gte($start->subSeconds($lead));
    }

    private function isHardStart(GeneratedPlaylist $rundown): bool
    {
        return $rundown->start_mode === 'hard'
            || $rundown->playlist?->start_mode === 'hard';
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

    /**
     * Findet einen fertigen Hard-Start-Rundown für die aktuelle Stunde.
     *
     * Hard gilt, wenn entweder der Rundown-Snapshot ODER die aktuelle Playlist
     * auf 'hard' steht – so wirkt das Umschalten sofort, auch ohne Neu-Generieren.
     */
    private function findHardRundownForNow(Station $station): ?GeneratedPlaylist
    {
        $candidate = GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')
            ->where('broadcast_date', today())
            ->where('broadcast_hour', now()->hour)
            ->with('playlist')
            ->first();

        if (! $candidate) {
            return null;
        }

        $isHard = $candidate->start_mode === 'hard'
            || $candidate->playlist?->start_mode === 'hard';

        return $isHard ? $candidate : null;
    }
}
