<?php

namespace App\Support\PlaylistElements;

use App\Models\PlaylistItem;
use App\Models\Station;
use Illuminate\Support\Collection;

/**
 * Works out when each element of a playlist starts and how long the whole thing runs.
 *
 * Fills run up to the next marker or the full hour, random elements count with their pool
 * average (approximate). Markers resume the time chain after unknown lengths; a hard marker
 * sets it exactly. In containers a fill keeps an open end.
 */
class PlaylistRuntime
{
    /** @var array<int, ?int> item id to start offset in seconds */
    private array $offsets = [];

    /** @var array<int, ?int> item id to length in seconds */
    private array $durations = [];

    /** @var array<int, bool> item id to "start offset is a guess" */
    private array $approximateOffsets = [];

    /** @var array<int, bool> item id to "length is a guess" */
    private array $approximateDurations = [];

    /** @var array<int, int> fill item id to the fixed time (second of the hour) it runs up to */
    private array $fillTargets = [];

    /** @var array<int, int> marker id to how late (+) or early (-) it is reached */
    private array $markerDeviations = [];

    private int $total = 0;

    private bool $totalIsApproximate = false;

    private bool $hasOpenEnd = false;

    private int $unknownCount = 0;

    private int $fillBudget = 0;

    /**
     * @param  Collection<int, PlaylistItem>  $items
     * @param  ?Station  $station  for random pool estimates
     * @param  bool  $isContainer  ignores markers and the end of hour
     */
    public static function for(Collection $items, ?Station $station = null, bool $isContainer = false): self
    {
        $runtime = new self;
        $lengths = new ElementLengths($station);
        $deadlines = FixedTimes::deadlines($items->all(), $lengths, $isContainer, $isContainer ? null : FixedTimes::endOfHour());

        $cursor = 0;
        $timeKnown = true;
        $approximate = false;

        foreach ($items as $key => $item) {
            if (FixedTimes::isMarker($item, $isContainer)) {
                $fixedAt = (int) $item->relative_offset_seconds;

                if ($timeKnown) {
                    $runtime->markerDeviations[$item->id] = $cursor - $fixedAt;
                }

                // Hard sets the clock; soft only resumes it when unknown.
                if ($item->fixed_mode === 'hard') {
                    $cursor = $fixedAt;
                    $approximate = false;
                    $timeKnown = true;
                } elseif (! $timeKnown) {
                    $cursor = $fixedAt;
                    $approximate = true;
                    $timeKnown = true;
                }

                $runtime->offsets[$item->id] = $fixedAt;
                $runtime->durations[$item->id] = 0;
                $runtime->approximateOffsets[$item->id] = false;

                continue;
            }

            $deadline = $deadlines[$key] ?? null;

            $runtime->offsets[$item->id] = $timeKnown ? $cursor : null;
            $runtime->approximateOffsets[$item->id] = $timeKnown && $approximate;

            $duration = $runtime->lengthOf($item, $lengths, $deadline, $timeKnown ? $cursor : null);
            $runtime->durations[$item->id] = $duration;

            if ($deadline !== null && $item->type === 'fill') {
                $runtime->fillTargets[$item->id] = $deadline->anchor;
            }

            if ($duration === null) {
                $timeKnown = false;

                if ($item->type === 'fill' && $deadline === null) {
                    $runtime->hasOpenEnd = true;
                    $runtime->fillBudget += $item->fill_max_duration_seconds ?? FixedTimes::HOUR_SECONDS;
                } elseif ($item->type !== 'fill') {
                    $runtime->unknownCount++;
                }

                continue;
            }

            if ($runtime->approximateDurations[$item->id] ?? false) {
                $approximate = true;
                $runtime->totalIsApproximate = true;
            }

            $cursor += $duration;
            $runtime->total += $duration;
        }

        return $runtime;
    }

    public function offset(PlaylistItem $item): ?int
    {
        return $this->offsets[$item->id] ?? null;
    }

    /** Is the start time an estimate? */
    public function offsetIsApproximate(PlaylistItem $item): bool
    {
        return $this->approximateOffsets[$item->id] ?? false;
    }

    public function duration(PlaylistItem $item): ?int
    {
        return $this->durations[$item->id] ?? null;
    }

    /** Is the length an estimate? */
    public function durationIsApproximate(PlaylistItem $item): bool
    {
        return $this->approximateDurations[$item->id] ?? false;
    }

    /** Fixed time a fill runs up to (3600 = full hour). */
    public function fillTarget(PlaylistItem $item): ?int
    {
        return $this->fillTargets[$item->id] ?? null;
    }

    /** Seconds the marker is reached late (+) or early (-), null if unknown. */
    public function markerDeviation(PlaylistItem $item): ?int
    {
        return $this->markerDeviations[$item->id] ?? null;
    }

    /** Everything whose length is known or estimated, in seconds. */
    public function total(): int
    {
        return $this->total;
    }

    /** Does the total contain estimates? */
    public function totalIsApproximate(): bool
    {
        return $this->totalIsApproximate;
    }

    /** Is there a fill without a deadline (only in containers)? */
    public function hasOpenEnd(): bool
    {
        return $this->hasOpenEnd;
    }

    /**
     * How much music the open fill elements may add at most, in seconds.
     *
     * This is a ceiling, not a promise: the generator stops early when the library has
     * nothing left to play that keeps the rotation rules.
     */
    public function fillBudget(): int
    {
        return $this->fillBudget;
    }

    /** How many elements have a length that is only known at playout. */
    public function unknownCount(): int
    {
        return $this->unknownCount;
    }

    /** Is the known part still short of a full hour? */
    public function fitsInHour(): bool
    {
        return $this->total <= FixedTimes::HOUR_SECONDS;
    }

    public function remainingInHour(): int
    {
        return FixedTimes::HOUR_SECONDS - $this->total;
    }

    /** Share of the hour already filled, capped at 100 for the progress bar. */
    public function hourPercentage(): int
    {
        return (int) min(100, round($this->total / FixedTimes::HOUR_SECONDS * 100));
    }

    /** Formats seconds as MM:SS, or H:MM:SS once it runs past an hour. */
    public static function format(int $seconds): string
    {
        if ($seconds >= FixedTimes::HOUR_SECONDS) {
            return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** Length of one element; fills run up to their deadline when the start is known. */
    private function lengthOf(PlaylistItem $item, ElementLengths $lengths, ?Deadline $deadline, ?int $start): ?int
    {
        $length = $lengths->of($item);
        $this->approximateDurations[$item->id] = $length !== null && $lengths->isEstimated($item);

        if ($length !== null || $deadline === null || $start === null) {
            return $length;
        }

        if ($item->type === 'fill') {
            $length = max(0, $deadline->at - $start);

            if ($item->fill_max_duration_seconds !== null) {
                $length = min($length, $item->fill_max_duration_seconds);
            }
        } elseif ($item->type === 'container' && $item->containerPlaylist?->items->contains('type', 'fill')) {
            $length = max($lengths->ofOrZero($item), $deadline->at - $start);
        } else {
            return null;
        }

        $this->approximateDurations[$item->id] = true;

        return $length;
    }
}
