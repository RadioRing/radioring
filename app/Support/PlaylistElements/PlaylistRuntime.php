<?php

namespace App\Support\PlaylistElements;

use App\Models\PlaylistItem;
use Illuminate\Support\Collection;

/**
 * Works out when each element of a playlist starts and how long the whole thing runs.
 *
 * A playlist is a template, so not every length is known in advance: a fill element plays
 * music until its budget is used up (its maximum duration, an hour by default) or the
 * library runs out, a random element is one file of unknown length, and an external source
 * is only as long as its last download suggests. Everything from the first such element on
 * has no start time, which is shown as "-" instead of a made-up number.
 */
class PlaylistRuntime
{
    private const HOUR_SECONDS = 3600;

    /** @var array<int, ?int> item id to start offset in seconds */
    private array $offsets = [];

    /** @var array<int, ?int> item id to length in seconds */
    private array $durations = [];

    private int $total = 0;

    private bool $hasOpenEnd = false;

    private int $unknownCount = 0;

    private int $fillBudget = 0;

    /**
     * @param  Collection<int, PlaylistItem>  $items
     */
    public static function for(Collection $items): self
    {
        $runtime = new self;
        $cursor = 0;
        $timeKnown = true;

        foreach ($items as $item) {
            $duration = $runtime->lengthOf($item);

            $runtime->offsets[$item->id] = $timeKnown ? $cursor : null;
            $runtime->durations[$item->id] = $duration;

            if ($duration === null) {
                $timeKnown = false;

                if ($item->type === 'fill') {
                    $runtime->hasOpenEnd = true;
                    $runtime->fillBudget += $item->fill_max_duration_seconds ?? self::HOUR_SECONDS;
                } else {
                    $runtime->unknownCount++;
                }

                continue;
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

    public function duration(PlaylistItem $item): ?int
    {
        return $this->durations[$item->id] ?? null;
    }

    /** Everything whose length is known, in seconds. */
    public function total(): int
    {
        return $this->total;
    }

    /** Is there a fill element, whose length is decided when the rundown is generated? */
    public function hasOpenEnd(): bool
    {
        return $this->hasOpenEnd;
    }

    /**
     * How much music the fill elements may add at most, in seconds.
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
        return $this->total <= self::HOUR_SECONDS;
    }

    public function remainingInHour(): int
    {
        return self::HOUR_SECONDS - $this->total;
    }

    /** Share of the hour already filled, capped at 100 for the progress bar. */
    public function hourPercentage(): int
    {
        return (int) min(100, round($this->total / self::HOUR_SECONDS * 100));
    }

    /** Formats seconds as MM:SS, or H:MM:SS once it runs past an hour. */
    public static function format(int $seconds): string
    {
        if ($seconds >= self::HOUR_SECONDS) {
            return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** @return int|null null when the length is only known at playout */
    private function lengthOf(PlaylistItem $item): ?int
    {
        return match ($item->type) {
            // Fill plays until its budget is used up, random picks one file of unknown length.
            'fill', 'random' => null,
            // A marker, not audio: it costs no time in the playlist itself.
            'adbreak' => 0,
            'external' => $item->externalSource?->expected_duration_seconds ?? $item->duration_seconds,
            'container' => $this->lengthOfContainer($item),
            default => $item->mediaFile?->duration_seconds ?? $item->duration_seconds,
        };
    }

    /** A container is as long as its elements; one unknown element makes the whole block unknown. */
    private function lengthOfContainer(PlaylistItem $item): ?int
    {
        $container = $item->containerPlaylist;

        if (! $container) {
            return 0;
        }

        $total = 0;

        foreach ($container->items as $containerItem) {
            $length = $this->lengthOf($containerItem);

            if ($length === null) {
                return null;
            }

            $total += $length;
        }

        return $total;
    }
}
