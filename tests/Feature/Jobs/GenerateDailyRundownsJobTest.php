<?php

use App\Jobs\GenerateDailyRundownsJob;
use App\Jobs\GenerateRundownJob;
use App\Models\GeneratedPlaylist;
use App\Models\HourGridSlot;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

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
    // QUEUE_CONNECTION is sync in tests, so the dispatched per-hour jobs run inline.
    (new GenerateDailyRundownsJob)->handle();
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

test('every hour of the day is dispatched as its own job, in broadcast order', function () {
    Queue::fake();

    $station = Station::factory()->create(['user_id' => $this->user->id]);
    $weekday = $this->target->dayOfWeekIso - 1;

    foreach ([6, 7, 8] as $hour) {
        makeDailySlot($station, $weekday, $hour);
    }

    (new GenerateDailyRundownsJob)->handle();

    Queue::assertPushed(GenerateRundownJob::class, 3);

    $hours = collect(Queue::pushed(GenerateRundownJob::class))
        ->map(fn (GenerateRundownJob $job) => HourGridSlot::find($job->slotId)->hour)
        ->all();

    expect($hours)->toBe([6, 7, 8]);
});

test('the nightly flag is carried into the per hour job', function () {
    Queue::fake();

    $plain = Station::factory()->create(['user_id' => $this->user->id, 'regenerate_rundowns_nightly' => false]);
    $nightly = Station::factory()->create(['user_id' => $this->user->id, 'regenerate_rundowns_nightly' => true]);
    $weekday = $this->target->dayOfWeekIso - 1;

    makeDailySlot($plain, $weekday, 8);
    makeDailySlot($nightly, $weekday, 8);

    (new GenerateDailyRundownsJob)->handle();

    Queue::assertPushed(GenerateRundownJob::class, fn ($job) => $job->stationId === $plain->id && $job->force === false);
    Queue::assertPushed(GenerateRundownJob::class, fn ($job) => $job->stationId === $nightly->id && $job->force === true);
});

test('a slot that disappears after dispatch does not take the rest of the day with it', function () {
    Queue::fake();

    $station = Station::factory()->create(['user_id' => $this->user->id]);
    $weekday = $this->target->dayOfWeekIso - 1;

    foreach ([6, 7, 8] as $hour) {
        makeDailySlot($station, $weekday, $hour);
    }

    (new GenerateDailyRundownsJob)->handle();
    $jobs = collect(Queue::pushed(GenerateRundownJob::class));

    // The grid is edited between dispatch and execution.
    $station->hourGridSlots()->where('hour', 7)->delete();

    $generator = app(RundownGeneratorService::class);
    $jobs->each(fn (GenerateRundownJob $job) => $job->handle($generator));

    $hours = GeneratedPlaylist::where('station_id', $station->id)
        ->orderBy('broadcast_hour')
        ->pluck('broadcast_hour')
        ->all();

    expect($hours)->toBe([6, 8]);
});
