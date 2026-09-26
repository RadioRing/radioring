<?php

namespace App\Support\PlaylistElements;

/**
 * Second of the hour by which an element must end to keep the next fixed time.
 *
 * $mustReach: a fill must not fall short (hard fixed time, end of hour) instead of ending
 * as close as possible (soft).
 */
final readonly class Deadline
{
    /** Fixed time (or full hour) the deadline counts back from. */
    public int $anchor;

    public function __construct(
        public int $at,
        public bool $mustReach,
        ?int $anchor = null,
    ) {
        $this->anchor = $anchor ?? $at;
    }

    /** Same fixed time, $seconds earlier. */
    public function minus(int $seconds): self
    {
        return new self($this->at - $seconds, $this->mustReach, $this->anchor);
    }
}
