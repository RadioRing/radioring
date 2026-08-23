<?php

namespace App\Services;

use App\Models\GeneratedPlaylist;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Support\ProjectedPlaylistItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Baut aus den Stunden-Rundowns eine durchgehende, projizierte Tages-Playlist
 * für die Anzeige (mAirList-Stil): eine einzige Liste ab dem aktuell laufenden
 * Track bis zum Tagesende, mit dynamisch vorwärts gerechneten Sendezeiten und
 * "–"-Markierung für Items, die ein Hard-Start der Folgestunde abschneidet.
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

        $nowPlayingItem = $state?->nowPlayingItem;
        $anchorRundown = $nowPlayingItem?->generatedPlaylist;
        $anchorTime = $state?->now_playing_started_at
            ? CarbonImmutable::parse($state->now_playing_started_at)
            : CarbonImmutable::now();

        // Ohne laufenden Track: auf den Rundown der aktuellen Stunde zurückfallen.
        if (! $anchorRundown) {
            $anchorRundown = $this->currentHourRundown($station);

            if (! $anchorRundown) {
                return collect();
            }
        }

        $rundowns = $this->upcomingRundowns($station, $anchorRundown);

        $projected = [];
        $cursor = $anchorTime;

        foreach ($rundowns as $rundown) {
            $isAnchor = $rundown->id === $anchorRundown->id;
            $isHardBoundary = ! $isAnchor && $this->isHardStart($rundown);

            if ($isHardBoundary) {
                $hardTime = $this->fixedStartFor($rundown);

                // Bereits projizierte, noch ungespielte Items, die erst zum/nach dem
                // Hard-Cut beginnen würden, werden nie gesendet → "–".
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

            // Im Anker-Rundown bereits gespielte Items (vor now_playing) ausblenden.
            $items = $rundown->items;
            if ($isAnchor && $nowPlayingItem) {
                $items = $items->where('position', '>=', $nowPlayingItem->position)->values();
            }

            foreach ($items as $index => $item) {
                // Inverse Relation setzen, damit die View ohne N+1 auf Stunde/Rundown zugreift.
                $item->setRelation('generatedPlaylist', $rundown);

                $isPlaying = $nowPlayingItem !== null && $item->id === $nowPlayingItem->id;
                $start = $isPlaying ? $anchorTime : $cursor;

                $projected[] = [
                    'item' => $item,
                    'start' => $start,
                    'is_playing' => $isPlaying,
                    'is_skipped' => false,
                    'is_hard_boundary' => $isHardBoundary && $index === 0,
                ];

                $cursor = $start->addSeconds((int) ($item->duration_seconds ?? 0));
            }
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
            ->with(['items.mediaFile', 'playlist'])
            ->get();
    }

    private function currentHourRundown(Station $station): ?GeneratedPlaylist
    {
        return GeneratedPlaylist::where('station_id', $station->id)
            ->where('status', 'ready')
            ->whereDate('broadcast_date', today())
            ->where('broadcast_hour', now()->hour)
            ->with(['items.mediaFile', 'playlist'])
            ->first();
    }

    private function isHardStart(GeneratedPlaylist $rundown): bool
    {
        return $rundown->start_mode === 'hard'
            || $rundown->playlist?->start_mode === 'hard';
    }

    private function fixedStartFor(GeneratedPlaylist $rundown): CarbonImmutable
    {
        return CarbonImmutable::parse($rundown->broadcast_date->toDateString())
            ->setTime($rundown->broadcast_hour, 0, 0);
    }
}
