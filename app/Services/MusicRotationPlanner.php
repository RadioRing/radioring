<?php

namespace App\Services;

use App\Enums\FillFit;
use App\Models\MediaFile;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Picks music for fill and random elements.
 *
 * Penalty tiers, highest first:
 * 1. GVL: per 3h max 4 per artist (3 in a row), max 3 per album (2 in a row)
 * 2. same title within the title separation
 * 3. same artist directly before or after
 * 4. same artist within the station's artist separation
 * 5. same title at the same clock time 1 or 2 days apart
 *
 * Ties: fewest airings in 24h, then oldest title, then oldest artist; random within the
 * freshest slice. The timeline may contain later hours, so rules look both ways.
 *
 * @phpstan-type TimelineEntry array{id?: ?int, artist: ?string, album: ?string, at: CarbonInterface, music?: bool}
 * @phpstan-type Airing array{id: ?int, artist: ?string, album: ?string, at: int, music: bool}
 * @phpstan-type Ranked array{index: int, track: MediaFile, penalty: int, freshness: list<int>}
 */
class MusicRotationPlanner
{
    public const WINDOW_SECONDS = 10800;

    public const MAX_ARTIST_PER_WINDOW = 4;

    public const MAX_ARTIST_IN_A_ROW = 3;

    public const MAX_ALBUM_PER_WINDOW = 3;

    public const MAX_ALBUM_IN_A_ROW = 2;

    private const GVL_PENALTY = 1_000_000;

    private const TITLE_PENALTY = 10_000;

    private const ADJACENT_ARTIST_PENALTY = 1_000;

    private const ARTIST_SEPARATION_PENALTY = 100;

    private const SAME_CLOCK_TIME_PENALTY = 50;

    private const SAME_CLOCK_TIME_TOLERANCE = 3600;

    private const PLAYS_WINDOW_SECONDS = 86400;

    /** Max gap between a track's end and the next planned title to count as adjacent. */
    private const ADJACENT_SLACK_SECONDS = 300;

    /** Max gap between two title starts to count as neighbours. */
    private const NEIGHBOUR_GAP_SECONDS = 1800;

    private const FRESH_SLICE = 0.1;

    private const FRESH_SLICE_MIN = 5;

    /** Candidates for backtiming the last tracks (60 give ~3500 pairs). */
    private const FINISH_CANDIDATES = 60;

    private int $titleSeparationSeconds;

    private int $historySeconds;

    private int $artistSeparationSeconds = 0;

    private int $violations = 0;

    public function __construct()
    {
        $this->titleSeparationSeconds = (int) config('radioring.rotation.title_separation_seconds', 28800);
        $this->historySeconds = (int) config('radioring.rotation.history_seconds', 172800);
    }

    /**
     * Timeline range needed before and after a slot.
     */
    public function historyWindowSeconds(): int
    {
        return max(self::WINDOW_SECONDS, $this->titleSeparationSeconds, $this->historySeconds);
    }

    /**
     * Tracks of the last plan() that break a GVL rule.
     */
    public function lastPlanViolations(): int
    {
        return $this->violations;
    }

    /**
     * Closest and Reach backtime the last one or two tracks; Reach adds one reserve track.
     *
     * @param  Collection<int, MediaFile>  $pool
     * @param  list<TimelineEntry>  $timeline
     * @return list<MediaFile> in broadcast order
     */
    public function plan(Collection $pool, array $timeline, Carbon $startAt, int $maxDuration, FillFit $fit = FillFit::Cross, int $artistSeparationSeconds = 0): array
    {
        $this->artistSeparationSeconds = max(0, $artistSeparationSeconds);
        $this->violations = 0;

        $remaining = $pool->values()->all();
        $timeline = $this->normalizeTimeline($timeline);
        $cursor = $startAt->getTimestamp();
        $filled = 0;
        $chosen = [];
        $longest = (int) $pool->max('duration_seconds');

        $place = function (MediaFile $track) use (&$timeline, &$chosen, &$cursor, &$filled): void {
            if ($this->gvlPenalty($track, $this->context($timeline, $cursor)) > 0) {
                $this->violations++;
            }

            $timeline[] = $this->airing($track, $cursor);
            $chosen[] = $track;
            $cursor += $track->duration_seconds ?? 0;
            $filled += $track->duration_seconds ?? 0;
        };

        while ($filled < $maxDuration && $remaining !== []) {
            $left = $maxDuration - $filled;
            $ranked = $this->rank($remaining, $timeline, $cursor);

            if ($fit !== FillFit::Cross && $left <= 2 * $longest) {
                $finish = $this->finish($ranked, $timeline, $cursor, $left, $fit);

                if ($finish !== null) {
                    array_map($place, $finish);

                    break;
                }
            }

            $index = $this->pickFromRanked($ranked);
            $place($remaining[$index]);
            array_splice($remaining, $index, 1);
        }

        // Reserve against drift on air; the playout drops it at the fixed time.
        if ($fit === FillFit::Reach && $chosen !== []) {
            $remaining = array_values(array_filter($remaining, fn (MediaFile $track): bool => ! in_array($track, $chosen, true)));

            if ($remaining !== []) {
                $place($remaining[$this->pickFromRanked($this->rank($remaining, $timeline, $cursor))]);
            }
        }

        return $chosen;
    }

    /**
     * Single pick for a random element, same rules as plan().
     *
     * @param  Collection<int, MediaFile>  $candidates
     * @param  list<TimelineEntry>  $timeline
     */
    public function pickOne(Collection $candidates, array $timeline, Carbon $at, int $artistSeparationSeconds = 0): ?MediaFile
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $this->artistSeparationSeconds = max(0, $artistSeparationSeconds);
        $remaining = $candidates->values()->all();

        return $remaining[$this->pickFromRanked($this->rank($remaining, $this->normalizeTimeline($timeline), $at->getTimestamp()))];
    }

    /**
     * Best zero, one or two tracks for the last $left seconds; null if Reach is impossible.
     *
     * @param  list<Ranked>  $ranked
     * @param  list<Airing>  $timeline
     * @return list<MediaFile>|null
     */
    private function finish(array $ranked, array $timeline, int $at, int $left, FillFit $fit): ?array
    {
        $lowest = $ranked[0]['penalty'];
        $candidates = array_column(array_slice($this->lowestTier($ranked), 0, self::FINISH_CANDIDATES), 'track');

        $deviation = fn (int $total): ?int => match ($fit) {
            FillFit::Reach => $total >= $left ? $total - $left : null,
            default => abs($total - $left),
        };

        // Closest may also stop right here.
        $best = $fit === FillFit::Closest ? [] : null;
        $bestDeviation = $fit === FillFit::Closest ? $left : PHP_INT_MAX;

        foreach ($candidates as $first) {
            $firstLength = $first->duration_seconds ?? 0;
            $single = $deviation($firstLength);

            if ($single !== null && $single < $bestDeviation) {
                $best = [$first];
                $bestDeviation = $single;
            }

            $secondAt = $at + $firstLength;
            $afterFirst = null;

            foreach ($candidates as $second) {
                if ($second === $first) {
                    continue;
                }

                $pair = $deviation($firstLength + ($second->duration_seconds ?? 0));

                if ($pair === null || $pair >= $bestDeviation) {
                    continue;
                }

                $afterFirst ??= $this->context([...$timeline, $this->airing($first, $at)], $secondAt);

                if ($this->penalty($second, $afterFirst) > $lowest) {
                    continue;
                }

                $best = [$first, $second];
                $bestDeviation = $pair;
            }
        }

        return $best;
    }

    /**
     * @param  list<MediaFile>  $remaining
     * @param  list<Airing>  $timeline
     * @return list<Ranked> best first
     */
    private function rank(array $remaining, array $timeline, int $at): array
    {
        $context = $this->context($timeline, $at);
        $rows = [];

        foreach ($remaining as $index => $track) {
            $rows[] = [
                'index' => $index,
                'track' => $track,
                'penalty' => $this->penalty($track, $context),
                'freshness' => $this->freshness($track, $context),
            ];
        }

        // Shuffle so equal candidates do not keep the pool order.
        shuffle($rows);
        usort($rows, fn (array $a, array $b): int => [$a['penalty'], ...$a['freshness']] <=> [$b['penalty'], ...$b['freshness']]);

        return $rows;
    }

    /**
     * @param  list<Ranked>  $ranked
     * @return list<Ranked>
     */
    private function lowestTier(array $ranked): array
    {
        return array_values(array_filter($ranked, fn (array $row): bool => $row['penalty'] === $ranked[0]['penalty']));
    }

    /**
     * Random index within the freshest slice of the lowest tier.
     *
     * @param  list<Ranked>  $ranked
     */
    private function pickFromRanked(array $ranked): int
    {
        $tier = $this->lowestTier($ranked);
        $slice = array_slice($tier, 0, max(self::FRESH_SLICE_MIN, (int) ceil(count($tier) * self::FRESH_SLICE)));

        return $slice[array_rand($slice)]['index'];
    }

    /**
     * Per-slot lookup tables for penalty() and freshness().
     *
     * @param  list<Airing>  $timeline
     * @return array{
     *     at: int,
     *     titleTimes: array<int, list<int>>,
     *     artistTimes: array<string, list<int>>,
     *     albumTimes: array<string, list<int>>,
     *     before: list<array{artist: ?string, album: ?string}>,
     *     after: list<array{artist: ?string, album: ?string}>,
     *     nextMusicAt: ?int
     * }
     */
    private function context(array $timeline, int $at): array
    {
        $titleTimes = $artistTimes = $albumTimes = $before = $after = [];

        foreach ($timeline as $entry) {
            if ($entry['id'] !== null) {
                $titleTimes[$entry['id']][] = $entry['at'];
            }

            if ($entry['artist'] !== null) {
                $artistTimes[$entry['artist']][] = $entry['at'];
            }

            if ($entry['album'] !== null) {
                $albumTimes[$entry['album']][] = $entry['at'];
            }

            if ($entry['music'] && abs($entry['at'] - $at) < self::WINDOW_SECONDS) {
                if ($entry['at'] < $at) {
                    $before[] = $entry;
                } else {
                    $after[] = $entry;
                }
            }
        }

        usort($before, fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        usort($after, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $nextMusicAt = $after[0]['at'] ?? null;

        return [
            'at' => $at,
            'titleTimes' => $titleTimes,
            'artistTimes' => $artistTimes,
            'albumTimes' => $albumTimes,
            'before' => $this->neighbourChain($before, $at),
            'after' => $this->neighbourChain($after, $nextMusicAt ?? $at),
            'nextMusicAt' => $nextMusicAt,
        ];
    }

    /**
     * Consecutive titles from $from on, stopping at the first gap.
     *
     * @param  list<Airing>  $entries  sorted away from $from
     * @return list<array{artist: ?string, album: ?string}>
     */
    private function neighbourChain(array $entries, int $from): array
    {
        $chain = [];

        foreach ($entries as $entry) {
            if (abs($from - $entry['at']) > self::NEIGHBOUR_GAP_SECONDS) {
                break;
            }

            $chain[] = ['artist' => $entry['artist'], 'album' => $entry['album']];
            $from = $entry['at'];
        }

        return $chain;
    }

    /**
     * 0 = no rule touched.
     *
     * @param  array<string, mixed>  $context  see context()
     */
    private function penalty(MediaFile $track, array $context): int
    {
        $at = $context['at'];
        $penalty = $this->gvlPenalty($track, $context);
        $titleTimes = $track->id !== null ? ($context['titleTimes'][$track->id] ?? []) : [];

        $penalty += $this->decayingPenalty(self::TITLE_PENALTY, $this->nearestDistance($titleTimes, $at), $this->titleSeparationSeconds);

        $artist = $this->normalize($track->artist);
        if ($artist !== null) {
            if (($context['before'][0]['artist'] ?? null) === $artist) {
                $penalty += self::ADJACENT_ARTIST_PENALTY;
            }

            if ($this->isAdjacentToNext($track, $context) && ($context['after'][0]['artist'] ?? null) === $artist) {
                $penalty += self::ADJACENT_ARTIST_PENALTY;
            }

            $penalty += $this->decayingPenalty(self::ARTIST_SEPARATION_PENALTY, $this->nearestDistance($context['artistTimes'][$artist] ?? [], $at), $this->artistSeparationSeconds);
        }

        foreach ($titleTimes as $time) {
            $distance = abs($at - $time);

            if (abs($distance - 86400) <= self::SAME_CLOCK_TIME_TOLERANCE || abs($distance - 172800) <= self::SAME_CLOCK_TIME_TOLERANCE) {
                $penalty += self::SAME_CLOCK_TIME_PENALTY;

                break;
            }
        }

        return $penalty;
    }

    /**
     * Base to 2x base, falling with distance; 0 at or beyond the separation.
     */
    private function decayingPenalty(int $base, ?int $distance, int $separation): int
    {
        if ($distance === null || $distance >= $separation) {
            return 0;
        }

        return $base + (int) round($base * ($separation - $distance) / $separation);
    }

    /**
     * @param  array<string, mixed>  $context  see context()
     */
    private function gvlPenalty(MediaFile $track, array $context): int
    {
        $penalty = 0;
        $adjacentAfter = $this->isAdjacentToNext($track, $context);

        $artist = $this->normalize($track->artist);
        if ($artist !== null) {
            $penalty += max(0, $this->maxWindowCount($context['artistTimes'][$artist] ?? [], $context['at']) - self::MAX_ARTIST_PER_WINDOW);
            $penalty += max(0, $this->runLength($context, 'artist', $artist, $adjacentAfter) - self::MAX_ARTIST_IN_A_ROW);
        }

        $album = $this->normalize($track->album);
        if ($album !== null) {
            $penalty += max(0, $this->maxWindowCount($context['albumTimes'][$album] ?? [], $context['at']) - self::MAX_ALBUM_PER_WINDOW);
            $penalty += max(0, $this->runLength($context, 'album', $album, $adjacentAfter) - self::MAX_ALBUM_IN_A_ROW);
        }

        return self::GVL_PENALTY * $penalty;
    }

    /**
     * Most airings (candidate included) in any 3h window containing $at.
     *
     * @param  list<int>  $times
     */
    private function maxWindowCount(array $times, int $at): int
    {
        $all = [...$times, $at];
        $max = 0;

        foreach ($all as $start) {
            if ($start > $at || $start + self::WINDOW_SECONDS <= $at) {
                continue;
            }

            $count = count(array_filter($all, fn (int $time): bool => $time >= $start && $time < $start + self::WINDOW_SECONDS));
            $max = max($max, $count);
        }

        return $max;
    }

    /**
     * Run of equal values the candidate would join (candidate included).
     *
     * @param  array<string, mixed>  $context  see context()
     */
    private function runLength(array $context, string $field, string $value, bool $adjacentAfter): int
    {
        $run = 1;

        foreach ([$context['before'], $adjacentAfter ? $context['after'] : []] as $neighbours) {
            foreach ($neighbours as $entry) {
                if ($entry[$field] !== $value) {
                    break;
                }
                $run++;
            }
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $context  see context()
     */
    private function isAdjacentToNext(MediaFile $track, array $context): bool
    {
        return $context['nextMusicAt'] !== null
            && $context['nextMusicAt'] <= $context['at'] + ($track->duration_seconds ?? 0) + self::ADJACENT_SLACK_SECONDS;
    }

    /**
     * Sort key, lower = fresher.
     *
     * @param  array<string, mixed>  $context  see context()
     * @return list<int>
     */
    private function freshness(MediaFile $track, array $context): array
    {
        $at = $context['at'];
        $titleTimes = $track->id !== null ? ($context['titleTimes'][$track->id] ?? []) : [];
        $artist = $this->normalize($track->artist);
        $artistTimes = $artist !== null ? ($context['artistTimes'][$artist] ?? []) : [];

        return [
            count(array_filter($titleTimes, fn (int $time): bool => abs($time - $at) < self::PLAYS_WINDOW_SECONDS)),
            -($this->nearestDistance($titleTimes, $at) ?? PHP_INT_MAX),
            -($this->nearestDistance($artistTimes, $at) ?? PHP_INT_MAX),
        ];
    }

    /**
     * @param  list<int>  $times
     */
    private function nearestDistance(array $times, int $at): ?int
    {
        return $times === [] ? null : min(array_map(fn (int $time): int => abs($at - $time), $times));
    }

    /**
     * @param  list<TimelineEntry>  $timeline
     * @return list<Airing>
     */
    private function normalizeTimeline(array $timeline): array
    {
        return array_map(fn (array $entry): array => [
            'id' => $entry['id'] ?? null,
            'artist' => $this->normalize($entry['artist'] ?? null),
            'album' => $this->normalize($entry['album'] ?? null),
            'at' => $entry['at']->getTimestamp(),
            'music' => $entry['music'] ?? (($entry['artist'] ?? null) !== null),
        ], $timeline);
    }

    /**
     * @return Airing
     */
    private function airing(MediaFile $track, int $at): array
    {
        return [
            'id' => $track->id,
            'artist' => $this->normalize($track->artist),
            'album' => $this->normalize($track->album),
            'at' => $at,
            'music' => ($track->type ?? 'music') === 'music',
        ];
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_strtolower($value);
    }
}
