<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * Embeds a reusable container. It is resolved into its own elements when the rundown
 * is generated, so nothing downstream ever sees a container.
 */
class ContainerElement implements PlaylistElementType
{
    public function rules(): array
    {
        return ['selectedContainerId' => 'required|integer'];
    }

    public function create(Playlist $playlist, ElementDraft $draft, int $position): int
    {
        // No container inside a container: one level keeps the rundown predictable.
        abort_if($playlist->isContainer(), 403);

        $container = $playlist->station->playlists()
            ->containers()
            ->findOrFail($draft->containerId);

        $playlist->items()->create([
            'position' => $position,
            'type' => 'container',
            'title' => $container->name,
            'container_playlist_id' => $container->id,
        ]);

        return 1;
    }
}
