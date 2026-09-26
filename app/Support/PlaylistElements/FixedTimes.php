<?php

namespace App\Support\PlaylistElements;

use App\Models\PlaylistItem;

/**
 * Fixed times set by marker elements (relative_offset_seconds, fixed_mode).
 *
 * - soft: no fill music starts after the time, the running track plays out.
 * - hard: the programme is cut and the next element starts on the second.
 *
 * Fills plan up to the next marker, or the full hour. Markers inside containers are ignored.
 */
class FixedTimes
{
    public const HOUR_SECONDS = 3600;

    /** Is this an effective marker? */
    public static function isMarker(PlaylistItem $item, bool $insideContainer = false): bool
    {
        return ! $insideContainer
            && $item->type === 'marker'
            && $item->relative_offset_seconds !== null;
    }

    /** End of the hour, the deadline of a playlist. */
    public static function endOfHour(): Deadline
    {
        return new Deadline(self::HOUR_SECONDS, mustReach: true);
    }

    /**
     * Deadline per element, computed backwards from the next marker or $parentDeadline.
     *
     * @param  iterable<int, PlaylistItem>  $items
     * @return array<int, ?Deadline> keyed like $items
     */
    public static function deadlines(iterable $items, ElementLengths $lengths, bool $insideContainer = false, ?Deadline $parentDeadline = null): array
    {
        $items = is_array($items) ? $items : iterator_to_array($items);
        $deadlines = [];
        $next = $parentDeadline;

        foreach (array_reverse(array_keys($items)) as $key) {
            $item = $items[$key];
            $deadlines[$key] = $next;

            $next = match (true) {
                self::isMarker($item, $insideContainer) => new Deadline(
                    (int) $item->relative_offset_seconds,
                    mustReach: $item->fixed_mode === 'hard',
                ),
                $next === null => null,
                default => $next->minus($lengths->ofOrZero($item)),
            };
        }

        return array_reverse($deadlines, true);
    }
}
