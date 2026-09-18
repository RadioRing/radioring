<?php

use App\Jobs\PreloadNextRundownJob;
use App\Models\GeneratedPlaylist;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->generator = app(RundownGeneratorService::class);
});

function runJob(?int $stationId = null): void
{
    (new PreloadNextRundownJob(stationId: $stationId))
        ->handle(app(RundownGeneratorService::class));
}

function makeSlotForJob(Station $station, int $weekday, int $hour): void
{
    $playlist = $station->playlists()->create([
        'name' => 'Test Playlist',
        'playback_mode' => 'sequential',
    ]);

    $station->hourGridSlots()->create([
        'weekday' => $weekday,
        'hour' => $hour,
        'playlist_id' => $playlist->id,
    ]);
}

test('generates rundown for next hour when slot exists', function () {
    $nextHour = now()->addHour()->startOfHour();
    $weekday = $nextHour->dayOfWeekIso - 1;

    makeSlotForJob($this->station, $weekday, $nextHour->hour);

    runJob();

    $rundown = GeneratedPlaylist::where('station_id', $this->station->id)
        ->where('broadcast_hour', $nextHour->hour)
        ->first();

    expect($rundown)->not->toBeNull()
        ->and($rundown->status)->toBe('ready');
});

test('skips station with no slot for next hour', function () {
    runJob();

    expect(GeneratedPlaylist::where('station_id', $this->station->id)->count())->toBe(0);
});

test('does not overwrite an already ready rundown', function () {
    $nextHour = now()->addHour()->startOfHour();
    $weekday = $nextHour->dayOfWeekIso - 1;

    makeSlotForJob($this->station, $weekday, $nextHour->hour);

    runJob();

    $generatedAt = GeneratedPlaylist::where('station_id', $this->station->id)->first()->generated_at;

    runJob();

    expect(GeneratedPlaylist::where('station_id', $this->station->id)->count())->toBe(1)
        ->and(GeneratedPlaylist::where('station_id', $this->station->id)->first()->generated_at->eq($generatedAt))->toBeTrue();
});

test('only processes the given station when stationId is set', function () {
    $otherStation = Station::factory()->create(['user_id' => $this->user->id]);

    $nextHour = now()->addHour()->startOfHour();
    $weekday = $nextHour->dayOfWeekIso - 1;

    makeSlotForJob($this->station, $weekday, $nextHour->hour);
    makeSlotForJob($otherStation, $weekday, $nextHour->hour);

    runJob(stationId: $this->station->id);

    expect(GeneratedPlaylist::where('station_id', $this->station->id)->count())->toBe(1)
        ->and(GeneratedPlaylist::where('station_id', $otherStation->id)->count())->toBe(0);
});

test('skips paused stations', function () {
    $this->station->update(['status' => 'paused']);

    $nextHour = now()->addHour()->startOfHour();
    $weekday = $nextHour->dayOfWeekIso - 1;

    makeSlotForJob($this->station, $weekday, $nextHour->hour);

    runJob();

    expect(GeneratedPlaylist::where('station_id', $this->station->id)->count())->toBe(0);
});

test('the whole horizon is generated, not just the next hour', function () {
    $currentHour = now()->startOfHour();

    for ($offset = 0; $offset <= 3; $offset++) {
        $target = $currentHour->copy()->addHours($offset);
        makeSlotForJob($this->station, $target->dayOfWeekIso - 1, $target->hour);
    }

    runJob();

    expect(GeneratedPlaylist::where('station_id', $this->station->id)->count())->toBe(4);
});

test('an hour beyond the horizon is left alone', function () {
    $beyond = now()->startOfHour()->addHours(4);
    makeSlotForJob($this->station, $beyond->dayOfWeekIso - 1, $beyond->hour);

    runJob();

    expect(GeneratedPlaylist::where('station_id', $this->station->id)
        ->where('broadcast_hour', $beyond->hour)
        ->count())->toBe(0);
});

test('a half generated draft is rebuilt', function () {
    $nextHour = now()->addHour()->startOfHour();
    makeSlotForJob($this->station, $nextHour->dayOfWeekIso - 1, $nextHour->hour);

    runJob();

    // Simulate what an aborted generation used to leave behind.
    $rundown = GeneratedPlaylist::where('station_id', $this->station->id)->first();
    $rundown->update(['status' => 'draft', 'generated_at' => null]);

    runJob();

    expect($rundown->fresh()->status)->toBe('ready')
        ->and($rundown->fresh()->generated_at)->not->toBeNull();
});

test('the running hour is covered too', function () {
    $currentHour = now()->startOfHour();
    makeSlotForJob($this->station, $currentHour->dayOfWeekIso - 1, $currentHour->hour);

    runJob();

    expect(GeneratedPlaylist::where('station_id', $this->station->id)
        ->where('broadcast_hour', $currentHour->hour)
        ->where('status', 'ready')
        ->exists())->toBeTrue();
});

test('a played rundown in the horizon is not touched', function () {
    $currentHour = now()->startOfHour();
    makeSlotForJob($this->station, $currentHour->dayOfWeekIso - 1, $currentHour->hour);

    runJob();
    $rundown = GeneratedPlaylist::where('station_id', $this->station->id)->first();
    $rundown->update(['status' => 'played']);
    $generatedAt = $rundown->generated_at;

    $this->travel(5)->minutes();
    runJob();

    expect($rundown->fresh()->status)->toBe('played')
        ->and($rundown->fresh()->generated_at->eq($generatedAt))->toBeTrue();
});
