<?php

namespace App\Services;

use App\Enums\FillFit;
use App\Models\MediaFile;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Wählt Musiktitel für ein Fill-Element so aus, dass GEMA/GVL-Rotationsregeln über
 * ein gleitendes 3-Stunden-Fenster eingehalten werden:
 *
 * - höchstens 4 Titel desselben Interpreten pro 3h-Fenster
 * - höchstens 3 Titel desselben Interpreten am Stück
 * - höchstens 3 Titel desselben Albums pro 3h-Fenster
 * - nie zwei Titel desselben Albums direkt hintereinander
 * - derselbe Titel innerhalb des Cooldown-Fensters: abklingende Strafe (Diversität)
 *
 * Reicht das Material nicht aus, um die Regeln einzuhalten, wird der jeweils am
 * wenigsten verletzende Titel gewählt („so gut wie möglich").
 */
class MusicRotationPlanner
{
    public const WINDOW_SECONDS = 10800; // 3 Stunden

    public const MAX_ARTIST_PER_WINDOW = 4;

    public const MAX_ARTIST_IN_A_ROW = 3;

    public const MAX_ALBUM_PER_WINDOW = 3;

    public const MAX_ALBUM_IN_A_ROW = 2;

    /**
     * Cooldown-Fenster (Sekunden), innerhalb dessen ein bereits gespielter Titel eine
     * abklingende Strafe erhält, und das Maximalgewicht dieser Strafe (für gerade eben
     * gespielte Titel). Konfigurierbar über config/radioring.php.
     */
    private int $titleCooldownSeconds;

    private int $titlePenalty;

    public function __construct()
    {
        $this->titleCooldownSeconds = (int) config('radioring.rotation.title_cooldown_seconds', 28800);
        $this->titlePenalty = (int) config('radioring.rotation.title_penalty', 5000);
    }

    /**
     * Wie weit zurück die History für die Penalty-Berechnung relevant ist
     * (Maximum aus Rotations- und Titel-Cooldown-Fenster).
     */
    public function historyWindowSeconds(): int
    {
        return max(self::WINDOW_SECONDS, $this->titleCooldownSeconds);
    }

    /** Candidates sampled for backtiming the last tracks (60 give ~3500 pairs). */
    private const FINISH_CANDIDATES = 60;

    /**
     * Plant die Reihenfolge der Fill-Tracks.
     *
     * Closest and Reach backtime the last one or two tracks to the budget; the rotation
     * rules still take precedence.
     *
     * @param  Collection<int, MediaFile>  $pool  verfügbare Musiktitel (Kandidaten)
     * @param  list<array{id?: ?int, artist: ?string, album: ?string, at: Carbon}>  $history  bereits gesendete/platzierte Tracks im Vorfeld, aufsteigend nach Zeit sortiert
     * @return list<MediaFile> gewählte Tracks in Sendereihenfolge
     */
    public function plan(Collection $pool, array $history, Carbon $startAt, int $maxDuration, FillFit $fit = FillFit::Cross): array
    {
        $remaining = $pool->values()->all();

        /** @var list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}> $timeline */
        $timeline = array_map(fn (array $e): array => [
            'id' => $e['id'] ?? null,
            'artist' => $this->normalize($e['artist'] ?? null),
            'album' => $this->normalize($e['album'] ?? null),
            'at' => $e['at'],
        ], $history);

        $cursor = $startAt->copy();
        $filled = 0;
        $chosen = [];
        $longest = (int) $pool->max('duration_seconds');

        $place = function (MediaFile $track) use (&$timeline, &$chosen, &$cursor, &$filled): void {
            $timeline[] = $this->timelineEntry($track, $cursor);
            $chosen[] = $track;
            $cursor = $cursor->copy()->addSeconds($track->duration_seconds ?? 0);
            $filled += $track->duration_seconds ?? 0;
        };

        while ($filled < $maxDuration && $remaining !== []) {
            $left = $maxDuration - $filled;

            // Two tracks can close the gap: fit the end.
            if ($fit !== FillFit::Cross && $left <= 2 * $longest) {
                $finish = $this->finish($remaining, $timeline, $cursor, $left, $fit);

                if ($finish !== null) {
                    foreach ($finish as $track) {
                        $place($track);
                    }

                    break;
                }
            }

            $index = $this->chooseIndex($remaining, $timeline, $cursor);
            $track = $remaining[$index];
            array_splice($remaining, $index, 1);

            $place($track);
        }

        return $chosen;
    }

    /**
     * Best zero, one or two tracks to close the last $left seconds, or null if Reach cannot
     * be met. Only candidates with the lowest available penalty are considered.
     *
     * @param  list<MediaFile>  $remaining
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     * @return list<MediaFile>|null tracks in broadcast order
     */
    private function finish(array $remaining, array $timeline, Carbon $at, int $left, FillFit $fit): ?array
    {
        $penalties = array_map(fn (MediaFile $track): int => $this->penalty($track, $timeline, $at), $remaining);
        $lowest = min($penalties);

        $candidates = array_keys($penalties, $lowest, true);
        shuffle($candidates);
        $candidates = array_map(fn (int $i): MediaFile => $remaining[$i], array_slice($candidates, 0, self::FINISH_CANDIDATES));

        $deviation = fn (int $total): ?int => match ($fit) {
            FillFit::Reach => $total >= $left ? $total - $left : null,
            default => abs($total - $left),
        };

        $best = null;
        $bestDeviation = PHP_INT_MAX;

        // Stopping now is an option for a soft fixed time.
        if ($fit === FillFit::Closest) {
            $best = [];
            $bestDeviation = $left;
        }

        foreach ($candidates as $first) {
            $firstLength = $first->duration_seconds ?? 0;
            $single = $deviation($firstLength);

            if ($single !== null && $single < $bestDeviation) {
                $best = [$first];
                $bestDeviation = $single;
            }

            $afterFirst = [...$timeline, $this->timelineEntry($first, $at)];
            $secondAt = $at->copy()->addSeconds($firstLength);

            foreach ($candidates as $second) {
                if ($second === $first) {
                    continue;
                }

                $pair = $deviation($firstLength + ($second->duration_seconds ?? 0));

                // Check the penalty only for pairs that improve the fit.
                if ($pair === null || $pair >= $bestDeviation || $this->penalty($second, $afterFirst, $secondAt) > $lowest) {
                    continue;
                }

                $best = [$first, $second];
                $bestDeviation = $pair;
            }
        }

        return $best;
    }

    /**
     * @return array{id: ?int, artist: ?string, album: ?string, at: Carbon}
     */
    private function timelineEntry(MediaFile $track, Carbon $at): array
    {
        return [
            'id' => $track->id,
            'artist' => $this->normalize($track->artist),
            'album' => $this->normalize($track->album),
            'at' => $at->copy(),
        ];
    }

    /**
     * Wählt den Index des nächsten Tracks: bevorzugt einen, der keine Regel verletzt;
     * sonst den mit der geringsten Strafe (Fallback bei zu wenig Material).
     *
     * @param  list<MediaFile>  $remaining
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     */
    private function chooseIndex(array $remaining, array $timeline, Carbon $at): int
    {
        /** @var list<int> $feasible */
        $feasible = [];
        /** @var array<int, int> $penalties */
        $penalties = [];

        foreach ($remaining as $i => $track) {
            $penalty = $this->penalty($track, $timeline, $at);
            $penalties[$i] = $penalty;

            if ($penalty === 0) {
                $feasible[] = $i;
            }
        }

        if ($feasible !== []) {
            return $feasible[array_rand($feasible)];
        }

        $min = min($penalties);
        /** @var list<int> $candidates */
        $candidates = array_keys($penalties, $min, true);

        return $candidates[array_rand($candidates)];
    }

    /**
     * Strafpunkte für das Platzieren eines Tracks an dieser Stelle. 0 = regelkonform.
     * Titel-Wiederholungen wiegen am schwersten, dann Interpret, dann Album.
     *
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     */
    private function penalty(MediaFile $track, array $timeline, Carbon $at): int
    {
        $penalty = 0;

        // Titel-Cooldown: derselbe Titel innerhalb des Fensters → abklingende Strafe.
        $penalty += $this->titleCooldownPenalty($track, $timeline, $at);

        $artist = $this->normalize($track->artist);
        if ($artist !== null) {
            $windowExcess = $this->windowCount($timeline, $at, 'artist', $artist) - (self::MAX_ARTIST_PER_WINDOW - 1);
            if ($windowExcess > 0) {
                $penalty += 1000 * $windowExcess;
            }

            $rowExcess = $this->trailingRun($timeline, 'artist', $artist) - (self::MAX_ARTIST_IN_A_ROW - 1);
            if ($rowExcess > 0) {
                $penalty += 1000 * $rowExcess;
            }
        }

        $album = $this->normalize($track->album);
        if ($album !== null) {
            $windowExcess = $this->windowCount($timeline, $at, 'album', $album) - (self::MAX_ALBUM_PER_WINDOW - 1);
            if ($windowExcess > 0) {
                $penalty += 10 * $windowExcess;
            }

            $rowExcess = $this->trailingRun($timeline, 'album', $album) - (self::MAX_ALBUM_IN_A_ROW - 1);
            if ($rowExcess > 0) {
                $penalty += 10 * $rowExcess;
            }
        }

        return $penalty;
    }

    /**
     * Abklingende Strafe, wenn derselbe Titel innerhalb des Cooldown-Fensters bereits
     * lief: gerade eben gespielt → volle Strafe, am Fensterende → ~0. Greift nur bei
     * bekannter Track-ID (In-Memory-Tracks ohne ID bleiben unbestraft).
     *
     * @param  list<array{id: ?int, artist: ?string, album: ?string, at: Carbon}>  $timeline
     */
    private function titleCooldownPenalty(MediaFile $track, array $timeline, Carbon $at): int
    {
        $id = $track->id;

        if ($id === null || $this->titleCooldownSeconds <= 0) {
            return 0;
        }

        $windowStart = $at->copy()->subSeconds($this->titleCooldownSeconds);
        $mostRecent = null;

        foreach ($timeline as $entry) {
            if (($entry['id'] ?? null) === $id && $entry['at'] >= $windowStart && $entry['at'] < $at) {
                if ($mostRecent === null || $entry['at'] > $mostRecent) {
                    $mostRecent = $entry['at'];
                }
            }
        }

        if ($mostRecent === null) {
            return 0;
        }

        $age = $at->getTimestamp() - $mostRecent->getTimestamp();
        $remaining = $this->titleCooldownSeconds - $age;

        return $remaining > 0
            ? (int) round($this->titlePenalty * $remaining / $this->titleCooldownSeconds)
            : 0;
    }

    /**
     * Anzahl der Einträge im 3h-Fenster vor $at mit identischem Wert im Feld.
     *
     * @param  list<array{artist: ?string, album: ?string, at: Carbon}>  $timeline
     */
    private function windowCount(array $timeline, Carbon $at, string $field, string $value): int
    {
        $windowStart = $at->copy()->subSeconds(self::WINDOW_SECONDS);
        $count = 0;

        foreach ($timeline as $entry) {
            if ($entry[$field] === $value && $entry['at'] >= $windowStart && $entry['at'] < $at) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Länge der ununterbrochenen Serie identischer Werte am Ende der Timeline.
     *
     * @param  list<array{artist: ?string, album: ?string, at: Carbon}>  $timeline
     */
    private function trailingRun(array $timeline, string $field, string $value): int
    {
        $run = 0;

        for ($i = count($timeline) - 1; $i >= 0; $i--) {
            if ($timeline[$i][$field] !== $value) {
                break;
            }
            $run++;
        }

        return $run;
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_strtolower($value);
    }
}
