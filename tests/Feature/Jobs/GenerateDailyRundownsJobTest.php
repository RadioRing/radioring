<?php

use App\Jobs\GenerateDailyRundownsJob;
use App\Models\GeneratedPlaylist;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->target = now()->addDay()->startOfDay();
});

function makeDailySlot(Station $station, int $weekday, int $hour): void
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

function runDailyJob(): void
{
    (new GenerateDailyRundownsJob)->handle(app(RundownGeneratorService::class));
}

test('a station without the nightly flag keeps its already generated rundown', function () {
    $station = Station::factory()->create(['user_id' => $this->user->id, 'regenerate_rundowns_nightly' => false]);
    $weekday = $this->target->dayOfWeekIso - 1;
    makeDailySlot($station, $weekday, 8);

    runDailyJob();
    $generatedAt = GeneratedPlaylist::where('station_id', $station->id)->first()->generated_at;

    $this->travel(5)->minutes();
    runDailyJob();

    $rundown = GeneratedPlaylist::where('station_id', $station->id)->first();
    expect(GeneratedPlaylist::where('station_id', $station->id)->count())->toBe(1)
        ->and($rundown->generated_at->eq($generatedAt))->toBeTrue();
});

test('a station with the nightly flag regenerates its rundown', function () {
    $station = Station::factory()->create(['user_id' => $this->user->id, 'regenerate_rundowns_nightly' => true]);
    $weekday = $this->target->dayOfWeekIso - 1;
    makeDailySlot($station, $weekday, 8);

    runDailyJob();
    $generatedAt = GeneratedPlaylist::where('station_id', $station->id)->first()->generated_at;

    $this->travel(5)->minutes();
    runDailyJob();

    $rundown = GeneratedPlaylist::where('station_id', $station->id)->first();
    expect(GeneratedPlaylist::where('station_id', $station->id)->count())->toBe(1)
        ->and($rundown->status)->toBe('ready')
        ->and($rundown->generated_at->gt($generatedAt))->toBeTrue();
});

test('regenerating picks up newly added content in the playlist', function () {
    $station = Station::factory()->create(['user_id' => $this->user->id, 'regenerate_rundowns_nightly' => true]);
    $weekday = $this->target->dayOfWeekIso - 1;

    $playlist = $station->playlists()->create(['name' => 'Test Playlist', 'playback_mode' => 'sequential']);
    $station->hourGridSlots()->create(['weekday' => $weekday, 'hour' => 8, 'playlist_id' => $playlist->id]);

    $trackA = $station->mediaFiles()->create(['title' => 'Track A', 'type' => 'music', 'file_path' => 'a.mp3', 'duration_seconds' => 100]);
    $playlist->items()->create(['position' => 0, 'type' => 'music', 'title' => 'Track A', 'media_file_id' => $trackA->id]);

    runDailyJob();
    expect(GeneratedPlaylist::where('station_id', $station->id)->first()->items)->toHaveCount(1);

    // Neuer Titel nach der ersten Generierung – soll beim nächtlichen Lauf einfließen.
    $trackB = $station->mediaFiles()->create(['title' => 'Track B', 'type' => 'music', 'file_path' => 'b.mp3', 'duration_seconds' => 100]);
    $playlist->items()->create(['position' => 1, 'type' => 'music', 'title' => 'Track B', 'media_file_id' => $trackB->id]);

    $this->travel(5)->minutes();
    runDailyJob();

    $items = GeneratedPlaylist::where('station_id', $station->id)->first()->items;
    expect($items)->toHaveCount(2)
        ->and($items->pluck('title')->all())->toContain('Track B');
});
