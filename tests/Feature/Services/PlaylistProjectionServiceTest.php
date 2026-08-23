<?php

use App\Models\GeneratedPlaylist;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Models\User;
use App\Services\PlaylistProjectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create(['user_id' => User::factory()]);
    $this->service = app(PlaylistProjectionService::class);
    Carbon::setTestNow('2026-05-12 10:30:00'); // Montag
});

/**
 * Erzeugt einen Rundown mit Items à $duration Sekunden.
 *
 * @return array{0: GeneratedPlaylist, 1: Collection}
 */
function makeRundown(Station $station, int $hour, string $startMode, int $count, int $duration = 1200): array
{
    $rundown = GeneratedPlaylist::create([
        'station_id' => $station->id,
        'broadcast_date' => '2026-05-12',
        'broadcast_hour' => $hour,
        'start_mode' => $startMode,
        'status' => 'ready',
        'generated_at' => now(),
    ]);

    $items = collect(range(0, $count - 1))->map(fn ($pos) => $rundown->items()->create([
        'position' => $pos,
        'title' => sprintf('H%02d-Track%d', $hour, $pos),
        'duration_seconds' => $duration,
        'source_type' => 'template_item',
    ]));

    return [$rundown, $items];
}

test('hides already played items before the now-playing track', function () {
    [$rundown, $items] = makeRundown($this->station, 10, 'soft', 4);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'now_playing_item_id' => $items[1]->id,
        'now_playing_started_at' => Carbon::parse('2026-05-12 10:30:00'),
    ]);

    $playlist = $this->service->project($this->station);

    expect($playlist)->toHaveCount(3) // Position 1,2,3 – Position 0 ausgeblendet
        ->and($playlist->first()->isPlaying)->toBeTrue()
        ->and($playlist->first()->item->id)->toBe($items[1]->id);
});

test('projects times forward from the real airplay anchor', function () {
    [$rundown, $items] = makeRundown($this->station, 10, 'soft', 3, duration: 600);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'now_playing_item_id' => $items[0]->id,
        'now_playing_started_at' => Carbon::parse('2026-05-12 10:30:00'),
    ]);

    $playlist = $this->service->project($this->station)->values();

    expect($playlist[0]->projectedStart->format('H:i:s'))->toBe('10:30:00')
        ->and($playlist[1]->projectedStart->format('H:i:s'))->toBe('10:40:00')
        ->and($playlist[2]->projectedStart->format('H:i:s'))->toBe('10:50:00');
});

test('soft start of next hour just continues the projection', function () {
    [$rundown, $items] = makeRundown($this->station, 10, 'soft', 2, duration: 1200);
    makeRundown($this->station, 11, 'soft', 1, duration: 1200);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'now_playing_item_id' => $items[0]->id,
        'now_playing_started_at' => Carbon::parse('2026-05-12 10:30:00'),
    ]);

    $playlist = $this->service->project($this->station)->values();

    // Stunde 10: 10:30, 10:50 (Überlauf über 11:00 hinaus), Stunde 11 läuft danach weiter.
    expect($playlist)->toHaveCount(3)
        ->and($playlist->every(fn ($p) => ! $p->isSkipped))->toBeTrue()
        ->and($playlist[2]->projectedStart->format('H:i:s'))->toBe('11:10:00');
});

test('hard start of next hour pre-empts overrunning items with "–"', function () {
    [$rundown, $items] = makeRundown($this->station, 10, 'soft', 4, duration: 1200);
    [$hard, $hardItems] = makeRundown($this->station, 11, 'hard', 2, duration: 1200);

    // now_playing = Position 1 (10:30). Folgezeiten: P2 10:50, P3 11:10.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'now_playing_item_id' => $items[1]->id,
        'now_playing_started_at' => Carbon::parse('2026-05-12 10:30:00'),
    ]);

    $playlist = $this->service->project($this->station)->values();

    $p1 = $playlist->firstWhere('item.id', $items[1]->id); // playing 10:30
    $p2 = $playlist->firstWhere('item.id', $items[2]->id); // 10:50 – läuft über die Grenze, nicht übersprungen
    $p3 = $playlist->firstWhere('item.id', $items[3]->id); // würde 11:10 starten → übersprungen
    $hardFirst = $playlist->firstWhere('item.id', $hardItems[0]->id);

    expect($p1->isPlaying)->toBeTrue()
        ->and($p2->isSkipped)->toBeFalse()
        ->and($p2->projectedStart->format('H:i:s'))->toBe('10:50:00')
        ->and($p3->isSkipped)->toBeTrue()
        ->and($p3->projectedStart)->toBeNull()
        ->and($hardFirst->isHardBoundary)->toBeTrue()
        ->and($hardFirst->projectedStart->format('H:i:s'))->toBe('11:00:00');
});

test('falls back to the current hour rundown when nothing is playing', function () {
    [$rundown, $items] = makeRundown($this->station, 10, 'soft', 2, duration: 600);

    // Kein LiquidsoapState → Anker = Rundown der aktuellen Stunde, Start = jetzt.
    $playlist = $this->service->project($this->station)->values();

    expect($playlist)->toHaveCount(2)
        ->and($playlist->every(fn ($p) => ! $p->isPlaying))->toBeTrue()
        ->and($playlist[0]->projectedStart->format('H:i:s'))->toBe('10:30:00');
});
