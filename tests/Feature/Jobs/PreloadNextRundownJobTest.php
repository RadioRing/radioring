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
