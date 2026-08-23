<?php

namespace App\Services;

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\HourGridSlot;
use App\Models\LiquidsoapState;
use App\Models\PlaylistItem;
use App\Models\Station;
use App\Models\StationLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RundownGeneratorService
{
    public function __construct(private readonly MusicRotationPlanner $rotationPlanner) {}

    /**
     * Generiert einen Rundown für einen konkreten Sendetermin.
     *
     * - status=played  → wird nie überschrieben
     * - status=ready   → nur bei $force=true
     * - status=draft   → immer überschrieben
     *
     * @throws \RuntimeException wenn status=played
     */
    public function generate(Station $station, HourGridSlot $slot, Carbon $broadcastDate, bool $force = false): GeneratedPlaylist
    {
        $date = $broadcastDate->toDateString();

        $rundown = GeneratedPlaylist::where('station_id', $station->id)
            ->whereDate('broadcast_date', $date)
            ->where('broadcast_hour', $slot->hour)
            ->first();

        if ($rundown) {
            // Bereits gespielt: ohne force schützen, mit force (manueller Trigger)
            // bewusst neu generierbar – sonst lässt sich ein versehentlich/abgespielt
            // markierter Rundown nie wieder aktivieren.
            if ($rundown->isPlayed() && ! $force) {
                throw new \RuntimeException("Rundown für {$date} {$slot->hour}:00 wurde bereits gespielt und kann nicht überschrieben werden.");
            }

            if ($rundown->isReady() && ! $force) {
                return $rundown;
            }

            $rundown->items()->delete();
            $rundown->update([
                'hour_grid_slot_id' => $slot->id,
                'playlist_id' => $slot->playlist_id,
                'start_mode' => $slot->playlist->start_mode ?? 'soft',
                'status' => 'draft',
                'generated_at' => null,
            ]);
        } else {
            $rundown = GeneratedPlaylist::create([
                'station_id' => $station->id,
                'hour_grid_slot_id' => $slot->id,
                'playlist_id' => $slot->playlist_id,
                'start_mode' => $slot->playlist->start_mode ?? 'soft',
                'broadcast_date' => $date,
                'broadcast_hour' => $slot->hour,
                'status' => 'draft',
                'generated_at' => null,
            ]);
        }

        $broadcastStart = $broadcastDate->copy()->setHour($slot->hour)->setMinute(0)->setSecond(0);

        $this->resolveItems($station, $slot, $rundown, $broadcastStart);
        $this->calculateAbsoluteTimes($rundown, $broadcastStart);

        $rundown->update(['status' => 'ready', 'generated_at' => now()]);

        // Falls dieser Rundown gerade live ist: Abspiel-Cursor zurücksetzen. Die Items
        // haben neue IDs/Positionen – der alte Cursor wäre sonst inkonsistent. Der
        // now_playing-FK zeigt auf ein gelöschtes Item und wird genullt; der
        // denormalisierte Anzeige-Snapshot (Titel/Dauer/Startzeit) bleibt aber stehen,
        // damit der Player den real noch laufenden Track weiter anzeigt, bis Liquidsoap
        // beim nächsten Track-Wechsel einen frischen now_playing-Callback schickt.
        // Scope auf die Station (station_id ist unique/indiziert) statt nur auf
        // current_rundown_id – sonst sperrt der Update mangels Index per Table-Scan
        // ALLE liquidsoap_states-Zeilen und kollidiert mit dem nebenläufigen /next-Pull
        // (Deadlock). So wird nur die eine Zeile dieser Station gesperrt.
        DB::transaction(function () use ($station, $rundown) {
            LiquidsoapState::where('station_id', $station->id)
                ->where('current_rundown_id', $rundown->id)
                ->update([
                    'current_item_position' => 0,
                    'now_playing_item_id' => null,
                ]);
        }, 5);

        $itemCount = $rundown->items()->count();

        Log::info("Rundown generiert: Station #{$station->id}, {$date} {$slot->hour}:00, {$itemCount} Tracks");

        // Protokoll: Rundown-Generierung festhalten (für die Betreiber-Nachvollziehbarkeit).
        StationLog::create([
            'station_id' => $station->id,
            'event' => StationLog::EVENT_RUNDOWN_GENERATED,
            'generated_playlist_id' => $rundown->id,
            'message' => __(':date :hour:00 Uhr · :count Titel', [
                'date' => $broadcastDate->format('d.m.Y'),
                'hour' => sprintf('%02d', $slot->hour),
                'count' => $itemCount,
            ]),
            'occurred_at' => now(),
        ]);

        return $rundown->fresh();
    }

    /**
     * Löst die Template-Items in konkrete Rundown-Items auf.
     *
     * Begleitend wird eine Sende-Timeline (Interpret/Album je Zeitpunkt) mitgeführt –
     * inklusive der Tracks aus den bereits generierten Stunden im 3h-Fenster davor –,
     * damit die Fill-Auswahl die Rotationsregeln über Stundengrenzen hinweg einhält.
     * Der Cursor spiegelt die Logik von calculateAbsoluteTimes wider.
     */
    private function resolveItems(Station $station, HourGridSlot $slot, GeneratedPlaylist $rundown, Carbon $broadcastStart): void
    {
        $template = $slot->playlist->load('items.mediaFile', 'items.externalSource');
        $position = 0;

        $cursor = $broadcastStart->copy();
        /** @var list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}> $timeline */
        $timeline = $this->historyTimeline($station, $rundown, $broadcastStart);

        foreach ($template->items as $item) {
            if ($item->type === 'fill') {
                $position = $this->resolveFillItem($station, $item, $rundown, $position, $cursor, $timeline);
            } elseif ($item->type === 'random') {
                $position = $this->resolveRandomItem($station, $item, $rundown, $position, $cursor, $timeline);
            } elseif ($item->type === 'adbreak') {
                $rundown->items()->create([
                    'position' => $position++,
                    'title' => 'START_AD_BREAK',
                    'source_type' => 'adbreak',
                ]);
                // Adbreaks unterbrechen eine Interpreten-/Album-Serie (kein Musiktitel).
                $timeline[] = ['id' => null, 'artist' => null, 'album' => null, 'at' => $cursor->copy()];
            } elseif (in_array($item->type, ['news', 'weather', 'news_weather'], true)) {
                // laut.fm-Nachrichten/Wetter – die /api/next-API baut zur Laufzeit die
                // authentifizierte radioadmin-URL aus den Credentials des laut.fm-Ausgangs.
                $newsDuration = (int) config('radioring.news_duration_seconds', 300);
                $rundown->items()->create([
                    'position' => $position++,
                    'title' => $item->title,
                    'duration_seconds' => $newsDuration,
                    'source_type' => $item->type,
                ]);
                $timeline[] = ['id' => null, 'artist' => null, 'album' => null, 'at' => $cursor->copy()];
                $cursor = $cursor->copy()->addSeconds($newsDuration);
            } elseif ($item->type === 'external') {
                // Externe HTTP-Quelle (Syndication/News/Wetter): dynamischer Inhalt, der
                // kurz vor Ausspielung geholt wird (PrepareUpcomingHttpItemsJob). Die Dauer
                // ist dynamisch – die AKTUELLE erwartete Dauer der Quelle hat Vorrang vor
                // einem ggf. veralteten Snapshot am Playlist-Item.
                $duration = $item->externalSource?->expected_duration_seconds
                    ?? $item->duration_seconds
                    ?? (int) config('radioring.news_duration_seconds', 300);

                $absoluteAt = $item->relative_offset_seconds !== null
                    ? $broadcastStart->copy()->addSeconds($item->relative_offset_seconds)
                    : null;

                $rundown->items()->create([
                    'position' => $position++,
                    'external_source_id' => $item->external_source_id,
                    'title' => $item->title,
                    'duration_seconds' => $duration,
                    'source_type' => 'external',
                    'absolute_broadcast_at' => $absoluteAt,
                ]);

                $at = $absoluteAt ?? $cursor->copy();
                $timeline[] = ['id' => null, 'artist' => null, 'album' => null, 'at' => $at];
                $cursor = $at->copy()->addSeconds($duration);
            } else {
                $duration = $item->duration_seconds ?? $item->mediaFile?->duration_seconds;

                // Relativen Offset direkt in absolute Zeit umrechnen wenn gesetzt
                $absoluteAt = $item->relative_offset_seconds !== null
                    ? $broadcastStart->copy()->addSeconds($item->relative_offset_seconds)
                    : null;

                $rundown->items()->create([
                    'position' => $position++,
                    'media_file_id' => $item->media_file_id,
                    // Pfad + Lautheit einfrieren: wird die Datei spaeter ersetzt, spielt
                    // dieser Rundown die alte Fassung zu Ende (siehe MediaReplacementService).
                    'media_file_path' => $item->mediaFile?->file_path,
                    'loudness_lufs' => $item->mediaFile?->loudness_lufs,
                    'loudness_true_peak' => $item->mediaFile?->loudness_true_peak,
                    'title' => $item->title,
                    'duration_seconds' => $duration,
                    'source_type' => 'template_item',
                    'absolute_broadcast_at' => $absoluteAt,
                ]);

                $at = $absoluteAt ?? $cursor->copy();
                $timeline[] = [
                    'id' => $item->media_file_id,
                    'artist' => $item->mediaFile?->artist,
                    'album' => $item->mediaFile?->album,
                    'at' => $at,
                ];
                $cursor = $at->copy()->addSeconds($duration ?? 0);
            }
        }
    }

    /**
     * Baut die Sende-Timeline der bereits generierten Stunden im 3h-Fenster vor dem
     * Sendestart (für die rundownübergreifende Rotationsprüfung).
     *
     * @return list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>
     */
    private function historyTimeline(Station $station, GeneratedPlaylist $rundown, Carbon $broadcastStart): array
    {
        // Fenster so breit wie der größere von Rotations- und Titel-Cooldown, damit der
        // Titel-Penalty auch Stunden zurückliegende Wiederholungen erkennt.
        $windowStart = $broadcastStart->copy()->subSeconds($this->rotationPlanner->historyWindowSeconds());

        return GeneratedPlaylistItem::query()
            ->whereNotNull('absolute_broadcast_at')
            ->where('absolute_broadcast_at', '>=', $windowStart)
            ->where('absolute_broadcast_at', '<', $broadcastStart)
            ->whereHas('generatedPlaylist', fn ($q) => $q
                ->where('station_id', $station->id)
                ->where('id', '!=', $rundown->id))
            ->with('mediaFile:id,artist,album')
            ->orderBy('absolute_broadcast_at')
            ->get()
            ->map(fn (GeneratedPlaylistItem $i): array => [
                'id' => $i->media_file_id,
                'artist' => $i->mediaFile?->artist,
                'album' => $i->mediaFile?->album,
                'at' => $i->absolute_broadcast_at,
            ])
            ->all();
    }

    /**
     * Berechnet für jedes Item die absolute Sendezeit anhand der kumulierten Dauer.
     */
    private function calculateAbsoluteTimes(GeneratedPlaylist $rundown, Carbon $broadcastStart): void
    {
        $cursor = $broadcastStart->copy();

        $rundown->items()->orderBy('position')->get()->each(function ($item) use (&$cursor) {
            if ($item->absolute_broadcast_at !== null) {
                // Relativer Offset wurde bereits in resolveItems berechnet – Cursor anpassen
                $cursor = $item->absolute_broadcast_at->copy()->addSeconds($item->duration_seconds ?? 0);
            } else {
                $item->update(['absolute_broadcast_at' => $cursor->copy()]);
                $cursor->addSeconds($item->duration_seconds ?? 0);
            }
        });
    }

    /**
     * Löst ein Fill-Element auf: wählt Tracks rotationskonform aus der Bibliothek und
     * fügt sie ein. Cursor und Timeline werden für nachfolgende Items fortgeschrieben.
     *
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     * @return int neue Position nach den eingefügten Tracks
     */
    private function resolveFillItem(Station $station, PlaylistItem $item, GeneratedPlaylist $rundown, int $position, Carbon &$cursor, array &$timeline): int
    {
        $maxDuration = $item->fill_max_duration_seconds ?? 3600;

        $query = $station->poolMediaFiles()->where('type', 'music');

        if (! empty($item->fill_tags)) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $item->fill_tags));
        }

        $tracks = $query->get();

        // Fallback: wenn keine Tracks mit Tags gefunden, alle Musik nehmen
        if ($tracks->isEmpty() && ! empty($item->fill_tags)) {
            $tracks = $station->poolMediaFiles()->where('type', 'music')->get();
        }

        $chosen = $this->rotationPlanner->plan($tracks, $timeline, $cursor, $maxDuration);

        foreach ($chosen as $track) {
            $rundown->items()->create([
                'position' => $position++,
                'media_file_id' => $track->id,
                'media_file_path' => $track->file_path,
                'loudness_lufs' => $track->loudness_lufs,
                'loudness_true_peak' => $track->loudness_true_peak,
                'title' => $track->title,
                'duration_seconds' => $track->duration_seconds,
                'source_type' => 'resolved_fill',
            ]);

            $timeline[] = [
                'id' => $track->id,
                'artist' => $track->artist,
                'album' => $track->album,
                'at' => $cursor->copy(),
            ];
            $cursor = $cursor->copy()->addSeconds($track->duration_seconds ?? 0);
        }

        return $position;
    }

    /**
     * Löst ein Zufalls-Element auf: wählt genau einen zufälligen Track aus dem
     * Stations-Pool, optional gefiltert nach Tags (z.B. für zufällige Jingles).
     * Findet sich kein passender Track, wird das Element übersprungen.
     *
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     * @return int neue Position nach dem eingefügten Track
     */
    private function resolveRandomItem(Station $station, PlaylistItem $item, GeneratedPlaylist $rundown, int $position, Carbon &$cursor, array &$timeline): int
    {
        $query = $station->poolMediaFiles();

        if (! empty($item->fill_tags)) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $item->fill_tags));
        }

        $track = $query->inRandomOrder()->first();

        if (! $track) {
            return $position;
        }

        $rundown->items()->create([
            'position' => $position++,
            'media_file_id' => $track->id,
            'media_file_path' => $track->file_path,
            'loudness_lufs' => $track->loudness_lufs,
            'loudness_true_peak' => $track->loudness_true_peak,
            'title' => $track->title,
            'duration_seconds' => $track->duration_seconds,
            'source_type' => 'resolved_random',
        ]);

        $timeline[] = [
            'id' => $track->id,
            'artist' => $track->artist,
            'album' => $track->album,
            'at' => $cursor->copy(),
        ];
        $cursor = $cursor->copy()->addSeconds($track->duration_seconds ?? 0);

        return $position;
    }
}
