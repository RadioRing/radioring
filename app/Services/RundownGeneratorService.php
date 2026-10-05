<?php

namespace App\Services;

use App\Enums\FillFit;
use App\Exceptions\RundownAlreadyPlayedException;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\HourGridSlot;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\PlaylistItem;
use App\Models\Station;
use App\Models\StationLog;
use App\Support\PlaylistElements\Deadline;
use App\Support\PlaylistElements\ElementLengths;
use App\Support\PlaylistElements\FixedTimes;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @phpstan-type TimelineEntry array{id: ?int, artist: ?string, album: ?string, at: CarbonInterface, music: bool}
 */
class RundownGeneratorService
{
    private ElementLengths $lengths;

    private int $artistSeparationSeconds = 0;

    /** GVL violations of the current rundown. */
    private int $rotationViolations = 0;

    public function __construct(private readonly MusicRotationPlanner $rotationPlanner) {}

    /**
     * played: never overwritten, ready: only with $force, draft: always overwritten.
     *
     * @throws RundownAlreadyPlayedException
     */
    public function generate(Station $station, HourGridSlot $slot, Carbon $broadcastDate, bool $force = false): GeneratedPlaylist
    {
        $date = $broadcastDate->toDateString();

        $rundown = GeneratedPlaylist::where('station_id', $station->id)
            ->whereDate('broadcast_date', $date)
            ->where('broadcast_hour', $slot->hour)
            ->first();

        if ($rundown) {
            // Force may revive a wrongly played-marked rundown.
            if ($rundown->isPlayed() && ! $force) {
                throw new RundownAlreadyPlayedException("Rundown für {$date} {$slot->hour}:00 wurde bereits gespielt und kann nicht überschrieben werden.");
            }

            if ($rundown->isReady() && ! $force) {
                return $rundown;
            }
        }

        $broadcastStart = $broadcastDate->copy()->setHour($slot->hour)->setMinute(0)->setSecond(0);

        // One transaction: an abort must not leave a half-built hour behind.
        $rundown = DB::transaction(function () use ($station, $slot, $rundown, $broadcastStart, $date): GeneratedPlaylist {
            if ($rundown) {
                $rundown->items()->delete();
                $rundown->update([
                    'hour_grid_slot_id' => $slot->id,
                    'playlist_id' => $slot->playlist_id,
                    'status' => 'draft',
                    'generated_at' => null,
                ]);
            } else {
                $rundown = GeneratedPlaylist::create([
                    'station_id' => $station->id,
                    'hour_grid_slot_id' => $slot->id,
                    'playlist_id' => $slot->playlist_id,
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

        // Live rundown: items got new IDs, so reset the playout cursor. The now playing
        // snapshot stays until Liquidsoap reports the next track. Scoped by station_id
        // (indexed) to avoid a table-scan lock that deadlocks with /next.
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

        if ($this->rotationViolations > 0) {
            StationLog::create([
                'station_id' => $station->id,
                'event' => StationLog::EVENT_ROTATION_VIOLATION,
                'generated_playlist_id' => $rundown->id,
                'message' => trans_choice(':date :hour:00: :count title breaks the GVL repeat rules (music pool too small).|:date :hour:00: :count titles break the GVL repeat rules (music pool too small).', $this->rotationViolations, [
                    'date' => $broadcastDate->format('d.m.Y'),
                    'hour' => sprintf('%02d', $slot->hour),
                    'count' => $this->rotationViolations,
                ]),
                'occurred_at' => now(),
            ]);
        }

        return $rundown->fresh();
    }

    /**
     * The cursor mirrors calculateAbsoluteTimes.
     */
    private function resolveItems(Station $station, HourGridSlot $slot, GeneratedPlaylist $rundown, Carbon $broadcastStart): void
    {
        $template = $slot->playlist->load('items.mediaFile', 'items.externalSource');

        $this->lengths = new ElementLengths($station);
        $this->artistSeparationSeconds = 60 * (int) $station->artist_separation_minutes;
        $this->rotationViolations = 0;
        $cursor = $broadcastStart->copy();
        $timeline = $this->surroundingTimeline($station, $rundown, $broadcastStart);

        $this->resolveTemplateItems($station, $template->items, $rundown, $broadcastStart, 0, $cursor, $timeline, parentDeadline: FixedTimes::endOfHour());
    }

    /**
     * Recursive for containers. A marker's fixed time goes to the next generated item.
     *
     * @param  iterable<int, PlaylistItem>  $items
     * @param  list<TimelineEntry>  $timeline
     * @return int next free position
     */
    private function resolveTemplateItems(Station $station, iterable $items, GeneratedPlaylist $rundown, Carbon $broadcastStart, int $position, Carbon &$cursor, array &$timeline, bool $insideContainer = false, ?Deadline $parentDeadline = null): int
    {
        $deadlines = FixedTimes::deadlines($items, $this->lengths, $insideContainer, $parentDeadline);
        $pendingMarker = null;

        foreach ($items as $key => $item) {
            if ($item->type === 'marker') {
                if (FixedTimes::isMarker($item, $insideContainer)) {
                    $pendingMarker = $item;

                    if ($item->fixed_mode === 'hard') {
                        $cursor = $broadcastStart->copy()->addSeconds((int) $item->relative_offset_seconds);
                    }
                }

                continue;
            }

            $firstPosition = $position;
            $deadline = $deadlines[$key] ?? null;

            if ($item->type === 'container') {
                $position = $this->resolveContainerItem($station, $item, $rundown, $broadcastStart, $position, $cursor, $timeline, $insideContainer, $deadline);
                $pendingMarker = $this->applyMarker($rundown, $pendingMarker, $broadcastStart, $firstPosition, $position);

                continue;
            }

            if ($item->type === 'fill') {
                $position = $this->resolveFillItem($station, $item, $rundown, $broadcastStart, $position, $cursor, $timeline, $deadline);
            } elseif ($item->type === 'random') {
                $position = $this->resolveRandomItem($station, $item, $rundown, $position, $cursor, $timeline);
            } elseif ($item->type === 'adbreak') {
                $rundown->items()->create([
                    'position' => $position++,
                    'title' => 'START_AD_BREAK',
                    'source_type' => 'adbreak',
                ]);
            } elseif (in_array($item->type, ['news', 'weather', 'news_weather'], true)) {
                // laut.fm: /api/next builds the authenticated URL at runtime.
                $newsDuration = (int) config('radioring.news_duration_seconds', 300);
                $rundown->items()->create([
                    'position' => $position++,
                    'title' => $item->title,
                    'duration_seconds' => $newsDuration,
                    'source_type' => $item->type,
                ]);
                $cursor = $cursor->copy()->addSeconds($newsDuration);
            } elseif ($item->type === 'external') {
                // Fetched before airing (PrepareUpcomingHttpItemsJob); the source's current length wins.
                $duration = $item->externalSource?->expected_duration_seconds
                    ?? $item->duration_seconds
                    ?? (int) config('radioring.news_duration_seconds', 300);

                $rundown->items()->create([
                    'position' => $position++,
                    'external_source_id' => $item->external_source_id,
                    'title' => $item->title,
                    'duration_seconds' => $duration,
                    'source_type' => 'external',
                ]);
                $cursor = $cursor->copy()->addSeconds($duration);
            } else {
                $duration = $item->duration_seconds ?? $item->mediaFile?->duration_seconds;

                $rundown->items()->create([
                    'position' => $position++,
                    'media_file_id' => $item->media_file_id,
                    // Frozen: a later file replacement does not touch this rundown.
                    'media_file_path' => $item->mediaFile?->file_path,
                    'loudness_lufs' => $item->mediaFile?->loudness_lufs,
                    'loudness_true_peak' => $item->mediaFile?->loudness_true_peak,
                    'title' => $item->title,
                    'duration_seconds' => $duration,
                    'source_type' => 'template_item',
                ]);

                if ($item->mediaFile) {
                    $timeline[] = $this->airing($item->mediaFile, $cursor);
                }
                $cursor = $cursor->copy()->addSeconds($duration ?? 0);
            }

            $pendingMarker = $this->applyMarker($rundown, $pendingMarker, $broadcastStart, $firstPosition, $position);
        }

        return $position;
    }

    /**
     * @return PlaylistItem|null still pending when nothing was generated
     */
    private function applyMarker(GeneratedPlaylist $rundown, ?PlaylistItem $marker, Carbon $broadcastStart, int $firstPosition, int $nextPosition): ?PlaylistItem
    {
        if ($marker === null || $nextPosition === $firstPosition) {
            return $marker;
        }

        $rundown->items()->where('position', $firstPosition)->update([
            'fixed_at' => $broadcastStart->copy()->addSeconds((int) $marker->relative_offset_seconds),
            'fixed_mode' => $marker->fixed_mode === 'hard' ? 'hard' : 'soft',
        ]);

        return null;
    }

    /**
     * Inlines the container's items. No nesting, no foreign containers.
     *
     * @param  list<TimelineEntry>  $timeline
     * @return int next free position
     */
    private function resolveContainerItem(Station $station, PlaylistItem $item, GeneratedPlaylist $rundown, Carbon $broadcastStart, int $position, Carbon &$cursor, array &$timeline, bool $insideContainer, ?Deadline $deadline): int
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

        return $this->resolveTemplateItems($station, $container->items, $rundown, $broadcastStart, $position, $cursor, $timeline, insideContainer: true, parentDeadline: $deadline);
    }

    /**
     * Airings of other rundowns before and after this hour. Items inside this hour are
     * the previous hour's reserve and are left out.
     *
     * @return list<TimelineEntry>
     */
    private function surroundingTimeline(Station $station, GeneratedPlaylist $rundown, Carbon $broadcastStart): array
    {
        $window = $this->rotationPlanner->historyWindowSeconds();
        $broadcastEnd = $broadcastStart->copy()->addHour();

        return GeneratedPlaylistItem::query()
            ->whereNotNull('media_file_id')
            ->where('absolute_broadcast_at', '>=', $broadcastStart->copy()->subSeconds($window))
            ->where('absolute_broadcast_at', '<', $broadcastEnd->copy()->addSeconds($window))
            ->where(fn ($q) => $q
                ->where('absolute_broadcast_at', '<', $broadcastStart)
                ->orWhere('absolute_broadcast_at', '>=', $broadcastEnd))
            ->whereHas('generatedPlaylist', fn ($q) => $q
                ->where('station_id', $station->id)
                ->where('id', '!=', $rundown->id))
            ->with('mediaFile:id,artist,album,type')
            ->get()
            ->filter(fn (GeneratedPlaylistItem $item): bool => $item->mediaFile !== null)
            ->map(fn (GeneratedPlaylistItem $item): array => $this->airing($item->mediaFile, $item->absolute_broadcast_at))
            ->values()
            ->all();
    }

    /**
     * Sequential, except that a hard fixed item starts at its fixed time.
     */
    private function calculateAbsoluteTimes(GeneratedPlaylist $rundown, Carbon $broadcastStart): void
    {
        $cursor = $broadcastStart->copy();

        $rundown->items()->orderBy('position')->get()->each(function (GeneratedPlaylistItem $item) use (&$cursor) {
            if ($item->isHardFixed()) {
                $cursor = $item->fixed_at->copy();
            }

            $item->update(['absolute_broadcast_at' => $cursor->copy()]);
            $cursor->addSeconds($item->duration_seconds ?? 0);
        });
    }

    /**
     * Budget: time to the deadline (Closest if soft, Reach if hard or end of hour), unless
     * the fill's own maximum is shorter.
     *
     * @param  list<TimelineEntry>  $timeline
     * @return int next free position
     */
    private function resolveFillItem(Station $station, PlaylistItem $item, GeneratedPlaylist $rundown, Carbon $broadcastStart, int $position, Carbon &$cursor, array &$timeline, ?Deadline $deadline): int
    {
        $maxDuration = $item->fill_max_duration_seconds;
        $fit = FillFit::Cross;

        if ($deadline !== null) {
            $untilDeadline = max(0, (int) $cursor->diffInSeconds($broadcastStart->copy()->addSeconds($deadline->at), false));

            if ($maxDuration === null || $untilDeadline <= $maxDuration) {
                $maxDuration = $untilDeadline;
                $fit = $deadline->mustReach ? FillFit::Reach : FillFit::Closest;
            }
        }

        $maxDuration ??= 3600;

        if ($maxDuration === 0) {
            return $position;
        }

        // Airtime windows are checked once, at the start of the block.
        $query = $station->poolMediaFiles()->where('type', 'music')->airableAt($cursor);

        if (! empty($item->fill_tags)) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $item->fill_tags));
        }

        $tracks = $query->get();

        // No tagged tracks: fall back to all music.
        if ($tracks->isEmpty() && ! empty($item->fill_tags)) {
            $tracks = $station->poolMediaFiles()->where('type', 'music')->airableAt($cursor)->get();
        }

        $chosen = $this->rotationPlanner->plan($tracks, $timeline, $cursor, $maxDuration, $fit, $this->artistSeparationSeconds);
        $this->rotationViolations += $this->rotationPlanner->lastPlanViolations();

        foreach ($chosen as $track) {
            $this->appendTrack($rundown, $position++, $track, 'resolved_fill', $cursor, $timeline);
        }

        return $position;
    }

    /**
     * One file from the pool (optionally by tag) that may air now; skipped if none.
     *
     * Voice tracks are left out: each one is spoken for its own spot in the hour.
     *
     * @param  list<TimelineEntry>  $timeline
     * @return int next free position
     */
    private function resolveRandomItem(Station $station, PlaylistItem $item, GeneratedPlaylist $rundown, int $position, Carbon &$cursor, array &$timeline): int
    {
        $query = $station->poolMediaFiles()->where('type', '!=', 'voicetrack')->airableAt($cursor);

        if (! empty($item->fill_tags)) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $item->fill_tags));
        }

        $track = $this->rotationPlanner->pickOne($query->get(), $timeline, $cursor, $this->artistSeparationSeconds);

        if ($track) {
            $this->appendTrack($rundown, $position++, $track, 'resolved_random', $cursor, $timeline);
        }

        return $position;
    }

    /**
     * @param  list<TimelineEntry>  $timeline
     */
    private function appendTrack(GeneratedPlaylist $rundown, int $position, MediaFile $track, string $sourceType, Carbon &$cursor, array &$timeline): void
    {
        $rundown->items()->create([
            'position' => $position,
            'media_file_id' => $track->id,
            'media_file_path' => $track->file_path,
            'loudness_lufs' => $track->loudness_lufs,
            'loudness_true_peak' => $track->loudness_true_peak,
            'title' => $track->title,
            'duration_seconds' => $track->duration_seconds,
            'source_type' => $sourceType,
        ]);

        $timeline[] = $this->airing($track, $cursor);
        $cursor = $cursor->copy()->addSeconds($track->duration_seconds ?? 0);
    }

    /**
     * @return TimelineEntry
     */
    private function airing(MediaFile $file, CarbonInterface $at): array
    {
        return [
            'id' => $file->id,
            'artist' => $file->artist,
            'album' => $file->album,
            'at' => $at->copy(),
            'music' => $file->type === 'music',
        ];
    }
}
