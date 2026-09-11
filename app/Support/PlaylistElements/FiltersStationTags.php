<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

trait FiltersStationTags
{
    /**
     * Keeps only tags that actually belong to the station, so a manipulated form cannot
     * pull in another station's tags.
     *
     * @param  list<int|string>  $tagIds
     * @return list<int>|null null when nothing is left to filter by
     */
    private function validTagIds(Playlist $playlist, array $tagIds): ?array
    {
        $stationTagIds = $playlist->station->tags()->pluck('id')->all();
        $valid = array_values(array_intersect(array_map('intval', $tagIds), $stationTagIds));

        return $valid ?: null;
    }
}
