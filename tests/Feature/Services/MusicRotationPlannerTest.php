<?php

use App\Models\MediaFile;
use App\Services\MusicRotationPlanner;
use Carbon\Carbon;

beforeEach(function () {
    $this->planner = new MusicRotationPlanner;
    $this->start = Carbon::parse('2026-05-12 10:00:00');
});

/**
 * Baut einen In-Memory-Track (ohne DB).
 */
function track(?string $artist, ?string $album, int $duration = 600, ?int $id = null): MediaFile
{
    $track = new MediaFile([
        'title' => ($artist ?? '?').' – '.($album ?? '?'),
        'artist' => $artist,
        'album' => $album,
        'duration_seconds' => $duration,
    ]);

    if ($id !== null) {
        $track->id = $id;
    }

    return $track;
}

/**
 * Rekonstruiert die Sendezeitpunkte der gewählten Tracks.
 *
 * @param  list<MediaFile>  $chosen
 * @return list<array{artist: ?string, album: ?string, at: Carbon}>
 */
function timelineOf(array $chosen, Carbon $start): array
{
    $cursor = $start->copy();
    $timeline = [];

    foreach ($chosen as $t) {
        $timeline[] = ['artist' => $t->artist, 'album' => $t->album, 'at' => $cursor->copy()];
        $cursor = $cursor->copy()->addSeconds($t->duration_seconds);
    }

    return $timeline;
}

/**
 * @param  list<MediaFile>  $chosen
 */
function maxRun(array $chosen, string $field): int
{
    $max = 0;
    $run = 0;
    $prev = null;

    foreach ($chosen as $t) {
        if ($prev === $t->$field) {
            $run++;
        } else {
            $run = 1;
            $prev = $t->$field;
        }
        $max = max($max, $run);
    }

    return $max;
}

/**
 * @param  list<array{artist: ?string, album: ?string, at: Carbon}>  $timeline
 */
function maxWindowCount(array $timeline, string $field): int
{
    $max = 0;

    foreach ($timeline as $ref) {
        $count = 0;
        $windowStart = $ref['at']->copy()->subSeconds(MusicRotationPlanner::WINDOW_SECONDS);
        foreach ($timeline as $e) {
            if ($e[$field] === $ref[$field] && $e['at'] > $windowStart && $e['at'] <= $ref['at']) {
                $count++;
            }
        }
        $max = max($max, $count);
    }

    return $max;
}

test('never places more than three tracks of the same artist in a row', function () {
    // Viele X (Album je verschieden, damit nur die Artist-Regel greift) + reichlich Filler.
    // maxDuration gedeckelt, damit die Filler nie ausgehen – nur dann muss die Regel halten
    // (geht das Material aus, ist Bündelung der legitime „so gut wie möglich"-Fallback).
    $pool = collect();
    foreach (range(1, 8) as $n) {
        $pool->push(track('X', 'X-Album-'.$n));
    }
    foreach (range(1, 25) as $n) {
        $pool->push(track('Filler'.$n, 'F'.$n));
    }

    $chosen = $this->planner->plan($pool, [], $this->start, 9000);

    expect(maxRun($chosen, 'artist'))->toBeLessThanOrEqual(MusicRotationPlanner::MAX_ARTIST_IN_A_ROW);
});

test('never places more than two tracks of the same album in a row', function () {
    // Viele Tracks desselben Albums (Artist je verschieden) + reichlich Fremdalben.
    $pool = collect();
    foreach (range(1, 8) as $n) {
        $pool->push(track('Artist'.$n, 'AL'));
    }
    foreach (range(1, 25) as $n) {
        $pool->push(track('Other'.$n, 'OTHER'.$n));
    }

    $chosen = $this->planner->plan($pool, [], $this->start, 9000);

    expect(maxRun($chosen, 'album'))->toBeLessThanOrEqual(MusicRotationPlanner::MAX_ALBUM_IN_A_ROW);
});

test('keeps at most four of the same artist within a three hour window', function () {
    // 6× Artist X (je 10 min), dazu reichlich Abwechslung, damit X nicht erzwungen wird.
    $pool = collect([
        track('X', 'X1'), track('X', 'X2'), track('X', 'X3'),
        track('X', 'X4'), track('X', 'X5'), track('X', 'X6'),
    ]);
    foreach (range(1, 30) as $n) {
        $pool->push(track('Filler'.$n, 'F'.$n));
    }

    // maxDuration so wählen, dass die Filler nie ausgehen – sonst bündeln sich am Ende
    // zwangsläufig die übrigen X (legitimer „so gut wie möglich"-Fallback).
    $chosen = $this->planner->plan($pool, [], $this->start, 9000);
    $timeline = timelineOf($chosen, $this->start);

    $artistWindow = maxWindowCount(
        array_values(array_filter($timeline, fn ($e) => $e['artist'] === 'X')),
        'artist'
    );

    expect($artistWindow)->toBeLessThanOrEqual(MusicRotationPlanner::MAX_ARTIST_PER_WINDOW);
});

test('respects history passed in from previous hours', function () {
    // Vorgeschichte: 3× Artist X direkt vor dem Start → X darf nicht als nächstes kommen.
    $history = [
        ['artist' => 'X', 'album' => 'H1', 'at' => $this->start->copy()->subMinutes(30)],
        ['artist' => 'X', 'album' => 'H2', 'at' => $this->start->copy()->subMinutes(20)],
        ['artist' => 'X', 'album' => 'H3', 'at' => $this->start->copy()->subMinutes(10)],
    ];

    $pool = collect([track('X', 'A1'), track('Y', 'B1')]);

    $chosen = $this->planner->plan($pool, $history, $this->start, 600);

    expect($chosen[0]->artist)->toBe('Y');
});

test('falls back gracefully when there is not enough variety', function () {
    // Nur ein Künstler/Album verfügbar → Regeln nicht einhaltbar, trotzdem alles platzieren.
    $pool = collect([
        track('Solo', 'OnlyAlbum'), track('Solo', 'OnlyAlbum'),
        track('Solo', 'OnlyAlbum'), track('Solo', 'OnlyAlbum'),
        track('Solo', 'OnlyAlbum'),
    ]);

    $chosen = $this->planner->plan($pool, [], $this->start, 100000);

    expect($chosen)->toHaveCount(5);
});

test('treats null artist and album as unconstrained breakers', function () {
    $pool = collect([
        track(null, null), track(null, null), track(null, null),
        track(null, null), track(null, null),
    ]);

    $chosen = $this->planner->plan($pool, [], $this->start, 100000);

    // Kein Constraint greift bei null → alle platziert, keine Exception.
    expect($chosen)->toHaveCount(5);
});

test('avoids replaying a title that aired recently (history)', function () {
    // Titel #1 lief vor 30 Minuten → innerhalb des 8h-Cooldowns soll der frische
    // Titel #2 bevorzugt werden, obwohl beide rotationskonform wären.
    $history = [
        ['id' => 1, 'artist' => 'A', 'album' => 'AL1', 'at' => $this->start->copy()->subMinutes(30)],
    ];

    $pool = collect([
        track('A', 'AL1', 600, id: 1),
        track('B', 'BL1', 600, id: 2),
    ]);

    $chosen = $this->planner->plan($pool, $history, $this->start, 600);

    expect($chosen[0]->id)->toBe(2);
});

test('does not repeat the same title within one fill block while alternatives exist', function () {
    // Drei verschiedene Titel, genug Platz für viele Slots: kein Titel soll sich
    // wiederholen, solange noch ungespielte (penalty-freie) Alternativen da sind.
    $pool = collect([
        track('A', 'AL1', 600, id: 1),
        track('B', 'BL1', 600, id: 2),
        track('C', 'CL1', 600, id: 3),
    ]);

    $chosen = $this->planner->plan($pool, [], $this->start, 1800);

    $ids = array_map(fn ($t) => $t->id, $chosen);
    expect($ids)->toHaveCount(3)
        ->and(array_unique($ids))->toHaveCount(3);
});

test('a title past the cooldown window is no longer penalized', function () {
    // Titel #1 lief vor 9h (> 8h-Cooldown) → keine Strafe mehr, darf normal gewählt werden.
    $history = [
        ['id' => 1, 'artist' => 'A', 'album' => 'AL1', 'at' => $this->start->copy()->subHours(9)],
    ];

    $pool = collect([track('A', 'AL1', 600, id: 1)]);

    $chosen = $this->planner->plan($pool, $history, $this->start, 600);

    expect($chosen)->toHaveCount(1)
        ->and($chosen[0]->id)->toBe(1);
});
