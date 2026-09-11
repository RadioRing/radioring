<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * Picks exactly one media file out of the tagged pool, freshly for every rundown.
 */
class RandomElement implements PlaylistElementType
{
    use FiltersStationTags;

    public function rules(): array
    {
        return [];
    }

    public function create(Playlist $playlist, ElementDraft $draft, int $position): int
    {
        $playlist->items()->create([
            'position' => $position,
            'type' => 'random',
            'title' => 'Zufälliges Element',
            'fill_tags' => $this->validTagIds($playlist, $draft->tagIds),
        ]);

        return 1;
    }
}
