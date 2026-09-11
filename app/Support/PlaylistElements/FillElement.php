<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * Adds rotation-aware music until the maximum duration is reached (an hour by default)
 * or the pool of matching files is used up. Optionally limited by tags.
 */
class FillElement implements PlaylistElementType
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
            'type' => 'fill',
            'title' => 'Auffüllen mit Musik',
            'fill_tags' => $this->validTagIds($playlist, $draft->tagIds),
            'fill_max_duration_seconds' => $draft->fillMaxDurationSeconds,
        ]);

        return 1;
    }
}
