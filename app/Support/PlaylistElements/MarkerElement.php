<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * Fixed time marker for the next element, added as 00:00 soft (see FixedTimes).
 */
class MarkerElement implements PlaylistElementType
{
    public function rules(): array
    {
        return [];
    }

    public function create(Playlist $playlist, ElementDraft $draft, int $position): int
    {
        $playlist->items()->create([
            'position' => $position,
            'type' => 'marker',
            'title' => 'Fixzeit',
            'relative_offset_seconds' => 0,
            'fixed_mode' => 'soft',
        ]);

        return 1;
    }
}
