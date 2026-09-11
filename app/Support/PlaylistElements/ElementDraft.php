<?php

namespace App\Support\PlaylistElements;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * One filled-in "add element" form, detached from the Livewire component.
 *
 * Every element type reads what it needs from here, so the component itself does not
 * have to know which field belongs to which type.
 */
class ElementDraft
{
    /**
     * @param  list<int>  $externalSourceIds  picked sources, in the order they were clicked
     * @param  list<int|string>  $tagIds  tag filter for fill and random elements
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title = '',
        public readonly string $url = '',
        public readonly ?int $durationSeconds = null,
        public readonly ?int $mediaFileId = null,
        public readonly array $externalSourceIds = [],
        public readonly ?int $containerId = null,
        public readonly array $tagIds = [],
        public readonly ?int $fillMaxDurationSeconds = null,
        public readonly ?int $relativeOffsetSeconds = null,
        public readonly ?TemporaryUploadedFile $upload = null,
    ) {}
}
