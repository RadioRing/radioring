<?php

namespace App\Support\PlaylistElements;

use App\Models\PlaylistItem;
use App\Models\Station;

/**
 * Length of a template element before generating: known lengths, the pool average for a
 * random element (estimate), none for a fill.
 */
class ElementLengths
{
    /** @var array<string, ?int> average pool length per tag filter */
    private array $poolAverages = [];

    public function __construct(private readonly ?Station $station = null) {}

    /** Length in seconds, null when only known at playout. */
    public function of(PlaylistItem $item): ?int
    {
        return match ($item->type) {
            'fill' => null,
            'random' => $this->randomEstimate($item),
            // laut.fm injects the ads server-side, the programme clock does not move.
            'adbreak' => 0,
            // Plays nothing.
            'marker' => 0,
            'external' => $item->externalSource?->expected_duration_seconds ?? $item->duration_seconds,
            'container' => $this->ofContainer($item),
            default => $item->mediaFile?->duration_seconds ?? $item->duration_seconds,
        };
    }

    /** Is the length an estimate (random element or container holding one)? */
    public function isEstimated(PlaylistItem $item): bool
    {
        if ($item->type === 'random') {
            return true;
        }

        if ($item->type === 'container') {
            return (bool) $item->containerPlaylist?->items->contains(fn (PlaylistItem $inner): bool => $inner->type === 'random');
        }

        return false;
    }

    /** Length with unknown parts counted as zero, for the stretch before a fixed time. */
    public function ofOrZero(PlaylistItem $item): int
    {
        if ($item->type === 'container') {
            return (int) $item->containerPlaylist?->items->sum(fn (PlaylistItem $inner): int => $this->of($inner) ?? 0);
        }

        return $this->of($item) ?? 0;
    }

    /** Sum of the container's elements, null if one is unknown. */
    private function ofContainer(PlaylistItem $item): ?int
    {
        $container = $item->containerPlaylist;

        if (! $container) {
            return 0;
        }

        $total = 0;

        foreach ($container->items as $containerItem) {
            $length = $this->of($containerItem);

            if ($length === null) {
                return null;
            }

            $total += $length;
        }

        return $total;
    }

    /** Average length of the random element's pool, null without a pool. */
    private function randomEstimate(PlaylistItem $item): ?int
    {
        if (! $this->station) {
            return null;
        }

        $tags = $item->fill_tags ?? [];
        sort($tags);
        $key = implode(',', $tags);

        if (! array_key_exists($key, $this->poolAverages)) {
            $query = $this->station->poolMediaFiles()->whereNotNull('duration_seconds');

            if ($tags !== []) {
                $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tags));
            }

            $average = $query->avg('duration_seconds');
            $this->poolAverages[$key] = $average !== null ? (int) round((float) $average) : null;
        }

        return $this->poolAverages[$key];
    }
}
