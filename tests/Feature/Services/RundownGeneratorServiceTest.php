<?php

use App\Models\GeneratedPlaylist;
use App\Models\HourGridSlot;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Models\StationLog;
use App\Models\User;
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

test('freezes the playlist start_mode into the generated rundown', function () {
    $slot = makeSlot($this->station);
    $slot->playlist->update(['start_mode' => 'hard']);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);

    expect($rundown->start_mode)->toBe('hard');
});

test('defaults the rundown start_mode to soft when the playlist is soft', function () {
    $slot = makeSlot($this->station);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);

    expect($rundown->start_mode)->toBe('soft');
});

test('resolves relative offset to absolute broadcast time', function () {
    $slot = makeSlot($this->station, hour: 10);
    $file = $this->station->mediaFiles()->create([
        'title' => 'Jingle',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/jingle.mp3',
    ]);
    $slot->playlist->items()->create([
        'position' => 0,
        'type' => 'jingle',
        'title' => 'Jingle',
        'media_file_id' => $file->id,
        'relative_offset_seconds' => 900, // 15 min
    ]);

    $rundown = $this->service->generate($this->station, $slot, $this->broadcastDate);

    $item = $rundown->items->first();
    expect($item->absolute_broadcast_at)->not->toBeNull()
        ->and($item->absolute_broadcast_at->format('H:i'))->toBe('10:15');
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
