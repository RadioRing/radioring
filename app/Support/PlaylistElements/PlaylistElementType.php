<?php

namespace App\Support\PlaylistElements;

use App\Models\Playlist;

/**
 * One kind of element that can be added to a playlist.
 *
 * Adding a new kind means writing one of these and registering it in ElementTypes,
 * instead of growing a branch in the editor component.
 */
interface PlaylistElementType
{
    /**
     * Validation rules for the add form.
     *
     * Keyed by the property of the editor component the operator filled in, so the
     * messages land on the right field in the form.
     *
     * @return array<string, string>
     */
    public function rules(): array;

    /**
     * Creates the element at the given position.
     *
     * @return int how many items were created; a single draft may add a whole block
     */
    public function create(Playlist $playlist, ElementDraft $draft, int $position): int;
}
