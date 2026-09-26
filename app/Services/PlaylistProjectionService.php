<?php

namespace App\Services;

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Support\ProjectedPlaylistItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Baut aus den Stunden-Rundowns eine durchgehende, projizierte Tages-Playlist
 * für die Anzeige (mAirList-Stil): eine einzige Liste ab dem aktuell laufenden
 * Track bis zum Tagesende, mit dynamisch vorwärts gerechneten Sendezeiten und
 * "–" for items cut by a hard fixed time or fill skipped for a fixed time.
 *
 * Reine Lese-/Projektionsschicht – verändert weder State noch Rundowns.
 */
class PlaylistProjectionService
{
    /**
     * @return Collection<int, ProjectedPlaylistItem>
     */
    public function project(Station $station): Collection
    {
        $state = LiquidsoapState::where('station_id', $station->id)
            ->with(['nowPlayingItem.generatedPlaylist'])
            ->first();

        $isStale = $state === null || $state->nowPlayingHasEnded();

        $nowPlayingItem = $isStale ? null : $state->nowPlayingItem;
        $anchorRundown = $nowPlayingItem?->generatedPlaylist;
        $anchorTime = ! $isStale && $state->now_playing_started_at
            ? CarbonImmutable::parse($state->now_playing_started_at)
            : CarbonImmutable::now();

        // Ohne laufenden Track: auf den Rundown der aktuellen Stunde zurückfallen.
        if (! $anchorRundown) {
            $anchorRundown = $this->currentHourRundown($station);

            if (! $anchorRundown) {
                return collect();
            }
        }

        // Flatten the hours into one list, without what already played.
        $entries = [];

        foreach ($this->upcomingRundowns($station, $anchorRundown) as $rundown) {
            $items = $rundown->items;

            if ($rundown->id === $anchorRundown->id && $nowPlayingItem) {
                $items = $items->where('position', '>=', $nowPlayingItem->position);
            }

            foreach ($items as $item) {
                // Set the inverse relation to avoid N+1 in the view.
                $item->setRelation('generatedPlaylist', $rundown);
                $entries[] = $item;
            }
        }

        $projected = [];
        $cursor = $anchorTime;

        foreach ($entries as $index => $item) {
            $isPlaying = $nowPlayingItem !== null && $item->id === $nowPlayingItem->id;
            $isHardBoundary = ! $isPlaying && $item->isHardFixed();

            if ($isHardBoundary) {
                $hardTime = CarbonImmutable::parse($item->fixed_at);

                // Items that would start at or after the cut are never sent.
                foreach ($projected as &$entry) {
                    if (! $entry['is_playing'] && ! $entry['is_skipped']
                        && $entry['start'] !== null && $entry['start']->gte($hardTime)) {
                        $entry['start'] = null;
                        $entry['is_skipped'] = true;
                    }
                }
                unset($entry);

                $cursor = $hardTime;
            }

            $start = $isPlaying ? $anchorTime : $cursor;

            // Fill skipped for a fixed time gets no airtime.
            if (! $isPlaying && ! $isHardBoundary
                && ($item->skipped_at !== null || $this->runsIntoFixedTime($entries, $index, $start))) {
                $projected[] = [
                    'item' => $item,
                    'start' => null,
                    'is_playing' => false,
                    'is_skipped' => true,
                    'is_hard_boundary' => false,
                ];

                continue;
            }

            $projected[] = [
                'item' => $item,
                'start' => $start,
                'is_playing' => $isPlaying,
                'is_skipped' => false,
                'is_hard_boundary' => $isHardBoundary,
            ];

            $cursor = $start->addSeconds((int) ($item->duration_seconds ?? 0));
        }

        return collect($projected)->map(fn (array $e) => new ProjectedPlaylistItem(
            item: $e['item'],
            projectedStart: $e['start'],
            isPlaying: $e['is_playing'],
            isSkipped: $e['is_skipped'],
            isHardBoundary: $e['is_hard_boundary'],
        ));
    }

    /**
     * Would this fill track start after the next fixed time? Mirrors
     * LiquidsoapStateService::skipFillPastFixedTime.
     *
     * @param  list<GeneratedPlaylistItem>  $entries
     */
    private function runsIntoFixedTime(array $entries, int $index, CarbonInterface $start): bool
    {
        $item = $entries[$index];

        if ($item->source_type !== 'resolved_fill') {
            return false;
        }

        $stillToPlay = 0;

        for ($i = $index + 1; $i < count($entries); $i++) {
            $next = $entries[$i];
            $sameHour = $next->generated_playlist_id === $item->generated_playlist_id;

            if (! $sameHour && $next->position !== 0) {
                return false;
            }

            if ($next->fixed_at !== null) {
                return $start->gte($next->fixed_at->copy()->subSeconds($stillToPlay));
            }

            if (! $sameHour) {
                return false;
            }

            if ($next->source_type !== 'resolved_fill') {
                $stillToPlay += (int) ($next->duration_seconds ?? 0);
            }
        }

        return false;
    }

    /**
     * Rundowns ab dem Anker bis Tagesende, in Sendereihenfolge.
     *
     * @return Collection<int, GeneratedPlaylist>
     */
    private function upcomingRundowns(Station $station, GeneratedPlaylist $anchorRundown): Collection
    {
        return GeneratedPlaylist::where('station_id', $station->id)
            ->whereIn('status', ['ready', 'played'])
            ->whereDate('broadcast_date', $anchorRundown->broadcast_date->toDateString())
            ->where('broadcast_hour', '>=', $anchorRundown->broadcast_hour)
            ->orderBy('broadcast_hour')
            ->with(['items.mediaFile', 'items.externalSource'])
            ->get();
    }

    private function currentHourRundown(Station $station): ?GeneratedPlaylist
    {
        return GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')
            ->whereDate('broadcast_date', today())
            ->where('broadcast_hour', now()->hour)
            ->with(['items.mediaFile', 'items.externalSource'])
            ->first();
    }
}
