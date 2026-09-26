<?php

use App\Models\GeneratedPlaylist;
use App\Models\HourGridSlot;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Models\StationLog;
use App\Models\User;
use App\Services\MusicRotationPlanner;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->service = app(RundownGeneratorService::class);
    $this->broadcastDate = Carbon::parse('2026-05-12'); // Montag
});

function makeSlot(Station $station, int $weekday = 0, int $hour = 10): HourGridSlot
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

test('generates a rundown with template items', function () {
    $slot = makeSlot($this->station);
    $file = $this->station->mediaFiles()->create([
        'title' => 'Morning Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
        'duration_seconds' => 200,
    ]);
    $slot->playlist->items()->create([
        'position' => 0,
        'type' => 'music',
        'title' => 'Morning Song',
        'media_file_id' => $file->id,
    ]);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);

    expect($rundown->status)->toBe('ready')
        ->and($rundown->items)->toHaveCount(1)
        ->and($rundown->items->first()->source_type)->toBe('template_item')
        ->and($rundown->items->first()->title)->toBe('Morning Song');
});

test('logs a rundown-generated event to the station protocol', function () {
    $slot = makeSlot($this->station);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);

    $log = StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_RUNDOWN_GENERATED)
        ->sole();

    expect($log->generated_playlist_id)->toBe($rundown->id)
        ->and($log->message)->not->toBeNull();
});

test('a hard 00:00 marker makes the hour start hard', function () {
    $slot = makeSlot($this->station, hour: 10);
    $file = $this->station->mediaFiles()->create([
        'title' => 'News', 'type' => 'jingle', 'file_path' => 'tenants/test/media/news.mp3', 'duration_seconds' => 120,
    ]);
    $slot->playlist->items()->create(['position' => 0, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => 0, 'fixed_mode' => 'hard']);
    $slot->playlist->items()->create(['position' => 1, 'type' => 'jingle', 'title' => 'News', 'media_file_id' => $file->id]);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);
    $first = $rundown->items->first();

    // The marker is not played, its time goes to the news.
    expect($rundown->items)->toHaveCount(1)
        ->and($first->title)->toBe('News')
        ->and($first->isHardFixed())->toBeTrue()
        ->and($first->fixed_at->format('H:i:s'))->toBe('10:00:00')
        ->and($rundown->startsHard())->toBeTrue();
});

test('an hour without a marker does not start hard', function () {
    $slot = makeSlot($this->station);
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song', 'type' => 'music', 'file_path' => 'tenants/test/media/song.mp3', 'duration_seconds' => 200,
    ]);
    $slot->playlist->items()->create(['position' => 0, 'type' => 'music', 'title' => 'Song', 'media_file_id' => $file->id]);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);

    expect($rundown->startsHard())->toBeFalse()
        ->and($rundown->items->first()->fixed_at)->toBeNull();
});

test('keeps the soft fixed time and plays sequentially when nothing fills up to it', function () {
    $slot = makeSlot($this->station, hour: 10);
    $file = $this->station->mediaFiles()->create([
        'title' => 'Jingle',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/jingle.mp3',
    ]);
    $slot->playlist->items()->create(['position' => 0, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => 900, 'fixed_mode' => 'soft']);
    $slot->playlist->items()->create([
        'position' => 1,
        'type' => 'jingle',
        'title' => 'Jingle',
        'media_file_id' => $file->id,
    ]);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);

    // No fill in front: the element plays sequentially, fixed_at stays nominal.
    $item = $rundown->items->first();
    expect($item->fixed_at->format('H:i'))->toBe('10:15')
        ->and($item->absolute_broadcast_at->format('H:i'))->toBe('10:00');
});

test('resolves fill item with random tracks', function () {
    $slot = makeSlot($this->station);
    foreach (['Song A', 'Song B', 'Song C'] as $title) {
        $this->station->mediaFiles()->create([
            'title' => $title,
            'type' => 'music',
            'file_path' => "stations/test/media/{$title}.mp3",
            'duration_seconds' => 200,
        ]);
    }
    $slot->playlist->items()->create([
        'position' => 0,
        'type' => 'fill',
        'title' => 'Auffüllen mit Musik',
        'fill_max_duration_seconds' => 500,
    ]);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);

    expect($rundown->items->every(fn ($i) => $i->source_type === 'resolved_fill'))->toBeTrue()
        ->and($rundown->items->count())->toBeGreaterThanOrEqual(1);
});

test('fill item filters by tags', function () {
    $tag = $this->station->tags()->create(['name' => '80er']);
    $slot = makeSlot($this->station);

    $tagged = $this->station->mediaFiles()->create([
        'title' => '80er Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/80er.mp3',
        'duration_seconds' => 200,
    ]);
    $tagged->tags()->attach($tag);

    $this->station->mediaFiles()->create([
        'title' => 'Andere Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/andere.mp3',
        'duration_seconds' => 200,
    ]);

    $slot->playlist->items()->create([
        'position' => 0,
        'type' => 'fill',
        'title' => 'Auffüllen mit Musik',
        'fill_tags' => [$tag->id],
        'fill_max_duration_seconds' => 3600,
    ]);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);

    expect($rundown->items->every(fn ($i) => $i->title === '80er Song'))->toBeTrue();
});

test('does not overwrite a played rundown', function () {
    $slot = makeSlot($this->station);
    GeneratedPlaylist::create([
        'station_id' => $this->station->id,
        'hour_grid_slot_id' => $slot->id,
        'playlist_id' => $slot->playlist_id,
        'broadcast_date' => $this->broadcastDate->toDateString(),
        'broadcast_hour' => $slot->hour,
        'status' => 'played',
        'generated_at' => now(),
    ]);

    expect(fn () => $this->service->generate($this->station, $slot, $this->broadcastDate))
        ->toThrow(RuntimeException::class);
});

test('does not regenerate a ready rundown without force', function () {
    $slot = makeSlot($this->station);
    $existing = GeneratedPlaylist::create([
        'station_id' => $this->station->id,
        'hour_grid_slot_id' => $slot->id,
        'playlist_id' => $slot->playlist_id,
        'broadcast_date' => $this->broadcastDate->toDateString(),
        'broadcast_hour' => $slot->hour,
        'status' => 'ready',
        'generated_at' => now()->subHour(),
    ]);

    $result = $this->service->generate($this->station, $slot, $this->broadcastDate);

    expect($result->id)->toBe($existing->id)
        ->and($result->generated_at->timestamp)->toBe($existing->generated_at->timestamp);
});

test('regenerates a ready rundown with force=true', function () {
    $slot = makeSlot($this->station);
    GeneratedPlaylist::create([
        'station_id' => $this->station->id,
        'hour_grid_slot_id' => $slot->id,
        'playlist_id' => $slot->playlist_id,
        'broadcast_date' => $this->broadcastDate->toDateString(),
        'broadcast_hour' => $slot->hour,
        'status' => 'ready',
        'generated_at' => now()->subHour(),
    ]);

    $result = $this->service->generate($this->station, $slot, $this->broadcastDate, force: true);

    expect($result->status)->toBe('ready')
        ->and($result->generated_at->gt(now()->subMinute()))->toBeTrue();
});

test('regenerating a live rundown resets the liquidsoap state cursor', function () {
    $slot = makeSlot($this->station);
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
        'duration_seconds' => 200,
    ]);
    $slot->playlist->items()->create([
        'position' => 0, 'type' => 'music', 'title' => 'Song', 'media_file_id' => $file->id,
    ]);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);
    $playingItem = $rundown->items->first();

    // Live-State simulieren: Cursor schon weit vorgelaufen (Prefetch), inkl. Snapshot
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 5,
        'now_playing_item_id' => $playingItem->id,
        'now_playing_title' => 'Song',
        'now_playing_source_type' => 'template_item',
        'now_playing_duration_seconds' => 200,
        'now_playing_started_at' => now(),
    ]);

    // Erneut generieren (force) → Items neu, State muss zurückgesetzt sein
    $this->service->generate($this->station, $slot, $this->broadcastDate, force: true);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_item_position)->toBe(0)
        ->and($state->now_playing_item_id)->toBeNull()
        // Der denormalisierte Snapshot überlebt, damit der Player den real noch
        // laufenden Track weiter anzeigt, bis der nächste Callback kommt.
        ->and($state->now_playing_title)->toBe('Song')
        ->and($state->now_playing_started_at)->not->toBeNull();
});

test('a failure during regeneration leaves the previous rundown intact', function () {
    $slot = makeSlot($this->station);
    $track = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'song.mp3',
        'duration_seconds' => 180,
    ]);
    $slot->playlist->items()->create([
        'position' => 0,
        'type' => 'music',
        'title' => 'Song',
        'media_file_id' => $track->id,
    ]);
    // A fill element, so the rotation planner is reached and can be made to fail.
    $slot->playlist->items()->create([
        'position' => 1,
        'type' => 'fill',
        'title' => 'Fill',
        'fill_max_duration_seconds' => 600,
    ]);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);
    $itemIds = $rundown->items()->orderBy('position')->pluck('id')->all();
    $generatedAt = $rundown->generated_at;

    expect($itemIds)->not->toBeEmpty();

    // The planner blows up halfway through the rebuild, after the items were wiped.
    $this->mock(MusicRotationPlanner::class, function ($mock) {
        $mock->shouldReceive('historyWindowSeconds')->andReturn(10800);
        $mock->shouldReceive('plan')->andThrow(new RuntimeException('boom'));
    });

    expect(fn () => app(RundownGeneratorService::class)
        ->generate($this->station, $slot, $this->broadcastDate, force: true))
        ->toThrow(RuntimeException::class, 'boom');

    $rundown->refresh();

    expect($rundown->status)->toBe('ready')
        ->and($rundown->generated_at->eq($generatedAt))->toBeTrue()
        ->and($rundown->items()->orderBy('position')->pluck('id')->all())->toBe($itemIds);
});
