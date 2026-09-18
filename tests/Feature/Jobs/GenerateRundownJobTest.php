<?php

use App\Jobs\GenerateRundownJob;
use App\Models\GeneratedPlaylist;
use App\Models\Station;
use App\Models\User;
use App\Services\MusicRotationPlanner;
use App\Services\RundownGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->date = now()->addDay()->startOfDay();
});

function makeSlotForHourJob(Station $station, int $weekday, int $hour)
{
    $playlist = $station->playlists()->create([
        'name' => 'Test Playlist',
        'playback_mode' => 'sequential',
    ]);

    return $station->hourGridSlots()->create([
        'weekday' => $weekday,
        'hour' => $hour,
        'playlist_id' => $playlist->id,
    ]);
}

function runHourJob(int $stationId, int $slotId, string $date, bool $force = false): void
{
    (new GenerateRundownJob($stationId, $slotId, $date, $force))
        ->handle(app(RundownGeneratorService::class));
}

test('generates the rundown of its hour', function () {
    $slot = makeSlotForHourJob($this->station, $this->date->dayOfWeekIso - 1, 9);

    runHourJob($this->station->id, $slot->id, $this->date->toDateString());

    $rundown = GeneratedPlaylist::where('station_id', $this->station->id)->first();

    expect($rundown)->not->toBeNull()
        ->and($rundown->broadcast_hour)->toBe(9)
        ->and($rundown->broadcast_date->toDateString())->toBe($this->date->toDateString())
        ->and($rundown->status)->toBe('ready');
});

test('a slot deleted in the meantime is skipped without failing', function () {
    $slot = makeSlotForHourJob($this->station, $this->date->dayOfWeekIso - 1, 9);
    $slotId = $slot->id;
    $slot->delete();

    runHourJob($this->station->id, $slotId, $this->date->toDateString());

    expect(GeneratedPlaylist::where('station_id', $this->station->id)->count())->toBe(0);
});

test('a slot of another station is never generated', function () {
    $other = Station::factory()->create(['user_id' => $this->user->id]);
    $slot = makeSlotForHourJob($other, $this->date->dayOfWeekIso - 1, 9);

    runHourJob($this->station->id, $slot->id, $this->date->toDateString());

    expect(GeneratedPlaylist::count())->toBe(0);
});

test('an already played hour is skipped instead of throwing', function () {
    $slot = makeSlotForHourJob($this->station, $this->date->dayOfWeekIso - 1, 9);

    runHourJob($this->station->id, $slot->id, $this->date->toDateString());
    GeneratedPlaylist::where('station_id', $this->station->id)->update(['status' => 'played']);

    runHourJob($this->station->id, $slot->id, $this->date->toDateString());

    expect(GeneratedPlaylist::where('station_id', $this->station->id)->first()->status)->toBe('played');
});

test('force rebuilds an hour that is already ready', function () {
    $slot = makeSlotForHourJob($this->station, $this->date->dayOfWeekIso - 1, 9);

    runHourJob($this->station->id, $slot->id, $this->date->toDateString());
    $generatedAt = GeneratedPlaylist::where('station_id', $this->station->id)->first()->generated_at;

    $this->travel(5)->minutes();
    runHourJob($this->station->id, $slot->id, $this->date->toDateString(), force: true);

    expect(GeneratedPlaylist::where('station_id', $this->station->id)->count())->toBe(1)
        ->and(GeneratedPlaylist::where('station_id', $this->station->id)->first()->generated_at->gt($generatedAt))->toBeTrue();
});

test('a real failure bubbles up so the queue can retry it', function () {
    $slot = makeSlotForHourJob($this->station, $this->date->dayOfWeekIso - 1, 9);
    $slot->playlist->items()->create([
        'position' => 0,
        'type' => 'fill',
        'title' => 'Fill',
        'fill_max_duration_seconds' => 600,
    ]);

    $this->mock(MusicRotationPlanner::class, function ($mock) {
        $mock->shouldReceive('historyWindowSeconds')->andReturn(10800);
        $mock->shouldReceive('plan')->andThrow(new RuntimeException('boom'));
    });

    expect(fn () => runHourJob($this->station->id, $slot->id, $this->date->toDateString()))
        ->toThrow(RuntimeException::class, 'boom');
});
