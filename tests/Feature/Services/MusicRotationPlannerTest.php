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

test('never places the same artist directly after each other while alternatives exist', function () {
    $pool = collect();
    foreach (range(1, 4) as $n) {
        $pool->push(track('X', 'X-Album-'.$n));
    }
    foreach (range(1, 12) as $n) {
        $pool->push(track('Filler'.$n, 'F'.$n));
    }

    $chosen = $this->planner->plan($pool, [], $this->start, 9000);

    expect(maxRun($chosen, 'artist'))->toBe(1);
});

test('a jingle between two titles does not break the artist run', function () {
    $history = [
        ['id' => 1, 'artist' => 'X', 'album' => 'X1', 'at' => $this->start->copy()->subMinutes(5), 'music' => true],
        ['id' => 2, 'artist' => null, 'album' => null, 'at' => $this->start->copy()->subSeconds(10), 'music' => false],
    ];

    $pool = collect([track('X', 'X2', 600, id: 3), track('Y', 'Y1', 600, id: 4)]);

    $chosen = $this->planner->plan($pool, $history, $this->start, 600);

    expect($chosen[0]->artist)->toBe('Y');
});

test('the GVL rules outweigh a title repeat', function () {
    // X already aired four times within the last two hours: a fifth X breaks the GVL
    // rule. Y aired 40 minutes ago: repeating it is the lesser evil.
    $history = [
        ['id' => 10, 'artist' => 'X', 'album' => 'X10', 'at' => $this->start->copy()->subMinutes(110)],
        ['id' => 11, 'artist' => 'X', 'album' => 'X11', 'at' => $this->start->copy()->subMinutes(90)],
        ['id' => 12, 'artist' => 'X', 'album' => 'X12', 'at' => $this->start->copy()->subMinutes(70)],
        ['id' => 13, 'artist' => 'X', 'album' => 'X13', 'at' => $this->start->copy()->subMinutes(50)],
        ['id' => 2, 'artist' => 'Y', 'album' => 'Y1', 'at' => $this->start->copy()->subMinutes(40)],
        ['id' => 20, 'artist' => 'Z', 'album' => 'Z1', 'at' => $this->start->copy()->subMinutes(20)],
    ];

    $pool = collect([track('X', 'X1', 600, id: 1), track('Y', 'Y1', 600, id: 2)]);

    $chosen = $this->planner->plan($pool, $history, $this->start, 600);

    expect($chosen[0]->id)->toBe(2)
        ->and($this->planner->lastPlanViolations())->toBe(0);
});

test('titles that never aired win over titles that aired earlier today', function () {
    $history = [];
    $pool = collect();

    foreach (range(1, 10) as $id) {
        // Aired 9 to 18 hours ago: past the minimum separation, but not fresh.
        $history[] = ['id' => $id, 'artist' => 'Aired'.$id, 'album' => 'A'.$id, 'at' => $this->start->copy()->subHours(8 + $id)];
        $pool->push(track('Aired'.$id, 'A'.$id, 600, id: $id));
    }
    foreach (range(11, 20) as $id) {
        $pool->push(track('Fresh'.$id, 'F'.$id, 600, id: $id));
    }

    foreach (range(1, 20) as $run) {
        $chosen = $this->planner->plan($pool, array_reverse($history), $this->start, 600);

        expect($chosen[0]->id)->toBeGreaterThan(10);
    }
});

test('titles with fewer airings in the last 24 hours are preferred', function () {
    $history = [];
    $pool = collect();

    // Titles 1 to 5 aired twice, titles 6 to 10 once (but more recently).
    foreach (range(1, 5) as $id) {
        $history[] = ['id' => $id, 'artist' => 'Twice'.$id, 'album' => 'T'.$id, 'at' => $this->start->copy()->subHours(20)];
        $history[] = ['id' => $id, 'artist' => 'Twice'.$id, 'album' => 'T'.$id, 'at' => $this->start->copy()->subHours(10)];
        $pool->push(track('Twice'.$id, 'T'.$id, 600, id: $id));
    }
    foreach (range(6, 10) as $id) {
        $history[] = ['id' => $id, 'artist' => 'Once'.$id, 'album' => 'O'.$id, 'at' => $this->start->copy()->subHours(9)];
        $pool->push(track('Once'.$id, 'O'.$id, 600, id: $id));
    }

    usort($history, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

    foreach (range(1, 20) as $run) {
        $chosen = $this->planner->plan($pool, $history, $this->start, 600);

        expect($chosen[0]->id)->toBeGreaterThan(5);
    }
});

test('a title avoids the clock time it aired at yesterday', function () {
    // Title 1 aired exactly 24 hours ago, title 2 20 hours ago. By age alone title 1
    // would win; the clock time rule keeps the day from repeating itself.
    $history = [
        ['id' => 1, 'artist' => 'A', 'album' => 'A1', 'at' => $this->start->copy()->subDay()],
        ['id' => 2, 'artist' => 'B', 'album' => 'B1', 'at' => $this->start->copy()->subHours(20)],
    ];

    $pool = collect([track('A', 'A1', 600, id: 1), track('B', 'B1', 600, id: 2)]);

    $chosen = $this->planner->plan($pool, $history, $this->start, 600);

    expect($chosen[0]->id)->toBe(2);
});

test('the artist separation of the station keeps an artist apart', function () {
    $history = [
        ['id' => 1, 'artist' => 'X', 'album' => 'X1', 'at' => $this->start->copy()->subMinutes(30)],
        ['id' => 2, 'artist' => 'Z', 'album' => 'Z1', 'at' => $this->start->copy()->subMinutes(10)],
    ];

    $pool = collect([track('X', 'X2', 600, id: 3), track('Y', 'Y1', 600, id: 4)]);

    foreach (range(1, 10) as $run) {
        $chosen = $this->planner->plan($pool, $history, $this->start, 600, artistSeparationSeconds: 2700);

        expect($chosen[0]->artist)->toBe('Y');
    }
});

test('an already planned following title counts as the next neighbour', function () {
    // The next hour opens with X right after this track ends.
    $timeline = [
        ['id' => 1, 'artist' => 'X', 'album' => 'X1', 'at' => $this->start->copy()->addSeconds(600), 'music' => true],
    ];

    $pool = collect([track('X', 'X2', 600, id: 2), track('Y', 'Y1', 600, id: 3)]);

    foreach (range(1, 10) as $run) {
        $chosen = $this->planner->plan($pool, $timeline, $this->start, 600);

        expect($chosen[0]->artist)->toBe('Y');
    }
});

test('the GVL window also counts the airings planned after the slot', function () {
    // Four X within the next two hours: a fifth X right now breaks the 3h rule.
    $timeline = [];
    foreach (range(1, 4) as $n) {
        $timeline[] = ['id' => 10 + $n, 'artist' => 'X', 'album' => 'XA'.$n, 'at' => $this->start->copy()->addMinutes(20 + 25 * $n)];
    }

    $pool = collect([track('X', 'X0', 600, id: 1), track('Y', 'Y1', 600, id: 2)]);

    foreach (range(1, 10) as $run) {
        $chosen = $this->planner->plan($pool, $timeline, $this->start, 600);

        expect($chosen[0]->artist)->toBe('Y');
    }
});

test('reports the tracks that break the GVL rules', function () {
    $pool = collect();
    foreach (range(1, 8) as $n) {
        $pool->push(track('Solo', 'Album'.$n, 600, id: $n));
    }

    $this->planner->plan($pool, [], $this->start, 4800);

    expect($this->planner->lastPlanViolations())->toBeGreaterThan(0);

    $varied = collect();
    foreach (range(1, 8) as $n) {
        $varied->push(track('Artist'.$n, 'Album'.$n, 600, id: $n));
    }

    $this->planner->plan($varied, [], $this->start, 4800);

    expect($this->planner->lastPlanViolations())->toBe(0);
});
