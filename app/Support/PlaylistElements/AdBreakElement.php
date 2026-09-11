<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * The laut.fm ad break marker. Liquidsoap signals the start of the break at this position.
 */
class AdBreakElement implements PlaylistElementType
{
    public function rules(): array
    {
        return [];
    }

    public function create(Playlist $playlist, ElementDraft $draft, int $position): int
    {
        $playlist->items()->create([
            'position' => $position,
            'type' => 'adbreak',
            'title' => 'Werbeunterbrechung (START_AD_BREAK)',
        ]);

        return 1;
    }
}
