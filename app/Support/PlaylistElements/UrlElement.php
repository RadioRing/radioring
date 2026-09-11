<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * A plain stream URL. Kept for old playlists; new dynamic content belongs into an
 * external source, which is fetched and normalised before it goes on air.
 */
class UrlElement implements PlaylistElementType
{
    public function rules(): array
    {
        return [
            'newTitle' => 'required|string|min:1|max:200',
            'newUrl' => 'required|url|max:2048',
            'newDuration' => 'nullable|integer|min:1|max:86400',
        ];
    }

    public function create(Playlist $playlist, ElementDraft $draft, int $position): int
    {
        $playlist->items()->create([
            'position' => $position,
            'type' => 'url',
            'title' => $draft->title,
            'url' => $draft->url,
            'duration_seconds' => $draft->durationSeconds,
            'relative_offset_seconds' => $draft->relativeOffsetSeconds,
        ]);

        return 1;
    }
}
