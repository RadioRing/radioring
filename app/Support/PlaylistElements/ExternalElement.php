<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * One or more external sources, added as a block in the order they were picked.
 */
class ExternalElement implements PlaylistElementType
{
    public function rules(): array
    {
        return [];
    }

    public function create(Playlist $playlist, ElementDraft $draft, int $position): int
    {
        $sources = $playlist->station->externalSources()
            ->whereIn('id', $draft->externalSourceIds)
            ->get()
            ->sortBy(fn ($source) => array_search($source->id, $draft->externalSourceIds))
            ->values();

        foreach ($sources as $index => $source) {
            $playlist->items()->create([
                'position' => $position + $index,
                'type' => 'external',
                'title' => $source->name,
                'external_source_id' => $source->id,
                // Kein Dauer-Snapshot: dynamische Quelle, die Länge wird bei der
                // Rundown-Generierung aus der aktuellen erwarteten Dauer gezogen.
                'duration_seconds' => null,
                // The timestamp pins the start of the block; the rest follows on directly.
                'relative_offset_seconds' => $index === 0 ? $draft->relativeOffsetSeconds : null,
            ]);
        }

        return $sources->count();
    }
}
