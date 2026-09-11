<?php

namespace App\Support\PlaylistElements;

/**
 * One row in the palette of the playlist editor.
 *
 * Media files, containers, external sources and the special elements all look the same
 * to the palette, so the editor can search and pick them in one list.
 */
class PaletteEntry
{
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $subtitle = null,
        public readonly ?int $durationSeconds = null,
        public readonly string $badge = '',
        public readonly string $badgeClass = 'bg-secondary',
        public readonly string $icon = 'bi-music-note-beamed',
    ) {}

    /** Stable handle the editor uses to pick and to insert this entry. */
    public function key(): string
    {
        return "{$this->kind}:{$this->id}";
    }

    public function durationFormatted(): ?string
    {
        if (! $this->durationSeconds) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($this->durationSeconds, 60), $this->durationSeconds % 60);
    }
}
