<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * Fills the rest of the hour with rotation-aware music, optionally limited by tags
 * and by a maximum duration.
 */
class FillElement implements PlaylistElementType
{
    use FiltersStationTags;

    public function rules(): array
    {
        return ['newFillMaxDuration' => 'nullable|integer|min:60|max:7200'];
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
