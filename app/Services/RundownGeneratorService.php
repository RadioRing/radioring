<?php

namespace App\Services;

use App\Exceptions\RundownAlreadyPlayedException;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\HourGridSlot;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\PlaylistItem;
use App\Models\Station;
use App\Models\StationLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RundownGeneratorService
{
    public function __construct(private readonly MusicRotationPlanner $rotationPlanner) {}

    /**
     * Generates the rundown for one broadcast slot.
     *
     * - status=played  never overwritten
     * - status=ready   only with $force
     * - status=draft   always overwritten
     *
     * @throws RundownAlreadyPlayedException when the rundown was already played
     */
    public function generate(Station $station, HourGridSlot $slot, Carbon $broadcastDate, bool $force = false): GeneratedPlaylist
    {
        $date = $broadcastDate->toDateString();

        $rundown = GeneratedPlaylist::where('station_id', $station->id)
            ->whereDate('broadcast_date', $date)
            ->where('broadcast_hour', $slot->hour)
            ->first();

        if ($rundown) {
            // Already played: protected without force, deliberately regeneratable with
            // it, or a wrongly played-marked rundown could never be revived.
            if ($rundown->isPlayed() && ! $force) {
                throw new RundownAlreadyPlayedException("Rundown für {$date} {$slot->hour}:00 wurde bereits gespielt und kann nicht überschrieben werden.");
            }

            if ($rundown->isReady() && ! $force) {
                return $rundown;
            }
        }

        $broadcastStart = $broadcastDate->copy()->setHour($slot->hour)->setMinute(0)->setSecond(0);

        // Everything that touches the rundown runs in one transaction. Rebuilding wipes
        // the items first, so an abort halfway through (a killed worker, a failing
        // element) would otherwise leave a draft with a partial hour behind: the
        // playout only ever serves "ready", so that hour would be silent. With the
        // rollback the previous version simply stays in place.
        $rundown = DB::transaction(function () use ($station, $slot, $rundown, $broadcastStart, $date): GeneratedPlaylist {
            if ($rundown) {
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

            $this->resolveItems($station, $slot, $rundown, $broadcastStart);
            $this->calculateAbsoluteTimes($rundown, $broadcastStart);

            $rundown->update(['status' => 'ready', 'generated_at' => now()]);

            return $rundown;
        }, 3);

        // If this rundown is live, reset the playout cursor: the items have new IDs
        // and positions, so the old cursor would be inconsistent. The now_playing FK
        // points at a deleted item and is nulled, but the denormalised display snapshot
        // stays so the player keeps showing the track that is really still running,
        // until Liquidsoap sends a fresh now_playing callback.
        // Scoped to the station (station_id is unique and indexed) rather than to
        // current_rundown_id alone: without an index the update locks EVERY
        // liquidsoap_states row by table scan and deadlocks with the concurrent
        // /next pull.
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

        // Record the generation in the station protocol.
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
     * Resolves the template items into concrete rundown items.
     *
     * A broadcast timeline (artist and album per moment) is carried along, including
     * the already generated hours in the 3h window before, so the fill selection keeps
     * the rotation rules across hour boundaries. The cursor mirrors the logic of
     * calculateAbsoluteTimes.
     */
    private function resolveItems(Station $station, HourGridSlot $slot, GeneratedPlaylist $rundown, Carbon $broadcastStart): void
    {
        $template = $slot->playlist->load('items.mediaFile', 'items.externalSource');

        $cursor = $broadcastStart->copy();
        /** @var list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}> $timeline */
        $timeline = $this->historyTimeline($station, $rundown, $broadcastStart);

        $this->resolveTemplateItems($station, $template->items, $rundown, $broadcastStart, 0, $cursor, $timeline);
    }

    /**
     * Resolves a list of template items into rundown items, in order.
     *
     * Runs for the playlist itself and, recursively, for every container embedded in it.
     * Containers are flattened here: the generated rundown only ever holds concrete items.
     *
     * @param  iterable<int, PlaylistItem>  $items
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     * @return int next free position
     */
    private function resolveTemplateItems(Station $station, iterable $items, GeneratedPlaylist $rundown, Carbon $broadcastStart, int $position, Carbon &$cursor, array &$timeline, bool $insideContainer = false): int
    {
        foreach ($items as $item) {
            // A fixed timestamp pins an item to a point in the hour, which contradicts a
            // block that is meant to be reusable anywhere: ignore it inside containers.
            $relativeOffset = $insideContainer ? null : $item->relative_offset_seconds;

            if ($item->type === 'container') {
                $position = $this->resolveContainerItem($station, $item, $rundown, $broadcastStart, $position, $cursor, $timeline, $insideContainer);

                continue;
            }

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
                // An adbreak breaks an artist or album streak: it is not a track.
                $timeline[] = ['id' => null, 'artist' => null, 'album' => null, 'at' => $cursor->copy()];
            } elseif (in_array($item->type, ['news', 'weather', 'news_weather'], true)) {
                // laut.fm news and weather: /api/next builds the authenticated
                // radioadmin URL at runtime from the credentials of the laut.fm output.
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
                // External HTTP source: dynamic content fetched shortly before airing
                // (PrepareUpcomingHttpItemsJob). The current expected duration of the
                // source wins over a possibly stale snapshot on the playlist item.
                $duration = $item->externalSource?->expected_duration_seconds
                    ?? $item->duration_seconds
                    ?? (int) config('radioring.news_duration_seconds', 300);

                $absoluteAt = $relativeOffset !== null
                    ? $broadcastStart->copy()->addSeconds($relativeOffset)
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

                // Turn a relative offset into absolute time right away.
                $absoluteAt = $relativeOffset !== null
                    ? $broadcastStart->copy()->addSeconds($relativeOffset)
                    : null;

                $rundown->items()->create([
                    'position' => $position++,
                    'media_file_id' => $item->media_file_id,
                    // Freeze path and loudness: if the file is replaced later, this
                    // rundown plays the old version out (see MediaReplacementService).
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

        return $position;
    }

    /**
     * Expands a container item: its own items are resolved in place, as if they had been
     * typed into the playlist directly. Containers do not nest, and a container from
     * another station is skipped.
     *
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     * @return int next free position
     */
    private function resolveContainerItem(Station $station, PlaylistItem $item, GeneratedPlaylist $rundown, Carbon $broadcastStart, int $position, Carbon &$cursor, array &$timeline, bool $insideContainer): int
    {
        if ($insideContainer) {
            return $position;
        }

        $container = $item->containerPlaylist;

        if (! $container || $container->station_id !== $station->id || ! $container->isContainer()) {
            Log::warning("Container-Element ohne gueltigen Container uebersprungen: Playlist-Item #{$item->id}");

            return $position;
        }

        $container->load('items.mediaFile', 'items.externalSource');

        return $this->resolveTemplateItems($station, $container->items, $rundown, $broadcastStart, $position, $cursor, $timeline, insideContainer: true);
    }

    /**
     * Builds the broadcast timeline of the already generated hours in the 3h window
     * before the start, for the rotation check across rundowns.
     *
     * @return list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>
     */
    private function historyTimeline(Station $station, GeneratedPlaylist $rundown, Carbon $broadcastStart): array
    {
        // The window spans the larger of the rotation and title cooldown so the title
        // penalty still catches repeats from hours ago.
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
     * Works out the absolute airtime of every item from the accumulated durations.
     */
    private function calculateAbsoluteTimes(GeneratedPlaylist $rundown, Carbon $broadcastStart): void
    {
        $cursor = $broadcastStart->copy();

        $rundown->items()->orderBy('position')->get()->each(function ($item) use (&$cursor) {
            if ($item->absolute_broadcast_at !== null) {
                // The relative offset was resolved in resolveItems; move the cursor.
                $cursor = $item->absolute_broadcast_at->copy()->addSeconds($item->duration_seconds ?? 0);
            } else {
                $item->update(['absolute_broadcast_at' => $cursor->copy()]);
                $cursor->addSeconds($item->duration_seconds ?? 0);
            }
        });
    }

    /**
     * Resolves a fill element: picks rotation-compliant tracks from the library and
     * inserts them, moving cursor and timeline on for the items that follow.
     *
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     * @return int next free position after the inserted tracks
     */
    private function resolveFillItem(Station $station, PlaylistItem $item, GeneratedPlaylist $rundown, int $position, Carbon &$cursor, array &$timeline): int
    {
        $maxDuration = $item->fill_max_duration_seconds ?? 3600;

        // Airtime windows are checked at the start of the block: the cursor moves on
        // during it, but a candidate once picked stays allowed.
        $query = $station->poolMediaFiles()->where('type', 'music')->airableAt($cursor);

        if (! empty($item->fill_tags)) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $item->fill_tags));
        }

        $tracks = $query->get();

        // Fallback: no tagged tracks left, so take all music.
        if ($tracks->isEmpty() && ! empty($item->fill_tags)) {
            $tracks = $station->poolMediaFiles()->where('type', 'music')->airableAt($cursor)->get();
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
     * Resolves a random element: picks exactly one track from the station pool,
     * optionally narrowed by tags. A gated file only qualifies when the planned
     * airtime falls into one of its windows; without a match the element is skipped.
     *
     * The pick is not blindly random: candidates that did not air recently win, so
     * several random elements in the same hour (and across the previous hours) work
     * through the pool instead of repeating the same file.
     *
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     * @return int next free position after the inserted track
     */
    private function resolveRandomItem(Station $station, PlaylistItem $item, GeneratedPlaylist $rundown, int $position, Carbon &$cursor, array &$timeline): int
    {
        $query = $station->poolMediaFiles()->airableAt($cursor);

        if (! empty($item->fill_tags)) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $item->fill_tags));
        }

        $track = $this->pickLeastRecentlyAired($query->get(), $timeline, $cursor);

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

    /**
     * Picks the candidate that aired longest ago, at random among equally old ones.
     * Never-aired candidates always win; only the airings before $at count, so the
     * choice follows the planned broadcast timeline, not the generation order.
     *
     * @param  Collection<int, MediaFile>  $candidates
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     */
    private function pickLeastRecentlyAired(Collection $candidates, array $timeline, Carbon $at): ?MediaFile
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        /** @var array<int, int> $lastAiredAt */
        $lastAiredAt = [];

        foreach ($timeline as $entry) {
            $id = $entry['id'] ?? null;

            if ($id === null || $entry['at'] > $at) {
                continue;
            }

            $timestamp = $entry['at']->getTimestamp();

            if (! isset($lastAiredAt[$id]) || $timestamp > $lastAiredAt[$id]) {
                $lastAiredAt[$id] = $timestamp;
            }
        }

        /** @var list<MediaFile> $best */
        $best = [];
        $bestAiredAt = null;

        foreach ($candidates as $candidate) {
            $airedAt = $lastAiredAt[$candidate->id] ?? null;

            if ($best === [] || $this->airsEarlier($airedAt, $bestAiredAt)) {
                $best = [$candidate];
                $bestAiredAt = $airedAt;

                continue;
            }

            if ($airedAt === $bestAiredAt) {
                $best[] = $candidate;
            }
        }

        return $best[array_rand($best)];
    }

    /**
     * Whether the first airing is older than the second; null means never aired and
     * therefore always older.
     */
    private function airsEarlier(?int $airedAt, ?int $comparedTo): bool
    {
        if ($comparedTo === null) {
            return false;
        }

        return $airedAt === null || $airedAt < $comparedTo;
    }
}
