<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * A media file picked from the library.
 */
class LibraryElement implements PlaylistElementType
{
    public function rules(): array
    {
        return ['selectedMediaFileId' => 'required|integer'];
    }

    public function create(Playlist $playlist, ElementDraft $draft, int $position): int
    {
        $mediaFile = $playlist->station->mediaFiles()->findOrFail($draft->mediaFileId);

        $playlist->items()->create([
            'position' => $position,
            'type' => $mediaFile->type,
            'title' => $mediaFile->title,
            'media_file_id' => $mediaFile->id,
            'relative_offset_seconds' => $draft->relativeOffsetSeconds,
        ]);

        return 1;
    }
}
