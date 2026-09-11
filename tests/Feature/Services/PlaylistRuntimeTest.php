<?php

use App\Models\ExternalSource;
use App\Models\Playlist;
use App\Models\Station;
use App\Models\User;
use App\Support\PlaylistElements\PlaylistRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->playlist = $this->station->playlists()->create([
        'name' => 'Test Playlist',
        'playback_mode' => 'sequential',
    ]);
});

/** Loads the playlist items the way the editor does. */
function runtimeFor(Playlist $playlist): PlaylistRuntime
{
    return PlaylistRuntime::for($playlist->items()
        ->with(['mediaFile', 'externalSource', 'containerPlaylist.items.mediaFile', 'containerPlaylist.items.externalSource'])
        ->orderBy('position')
        ->get());
}

function addMusic(Playlist $playlist, int $position, int $duration): void
{
    $file = test()->station->mediaFiles()->create([
        'title' => 'Track '.$position,
        'type' => 'music',
        'file_path' => 'tenants/test/media/track'.$position.'.mp3',
        'duration_seconds' => $duration,
    ]);

    $playlist->items()->create([
        'position' => $position,
        'type' => 'music',
        'title' => $file->title,
        'media_file_id' => $file->id,
    ]);
}

test('start times add up along the playlist', function () {
    addMusic($this->playlist, 0, 200);
    addMusic($this->playlist, 1, 185);
    addMusic($this->playlist, 2, 240);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    expect($runtime->offset($items[0]))->toBe(0)
        ->and($runtime->offset($items[1]))->toBe(200)
        ->and($runtime->offset($items[2]))->toBe(385)
        ->and($runtime->total())->toBe(625)
        ->and($runtime->fitsInHour())->toBeTrue()
        ->and($runtime->remainingInHour())->toBe(2975);
});

test('an ad break marker costs no time', function () {
    addMusic($this->playlist, 0, 200);
    $this->playlist->items()->create(['position' => 1, 'type' => 'adbreak', 'title' => 'Werbung']);
    addMusic($this->playlist, 2, 100);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    expect($runtime->offset($items[2]))->toBe(200)
        ->and($runtime->total())->toBe(300);
});

test('an external element counts with the expected duration of its source', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'expected_duration_seconds' => 300,
    ]);
    $this->playlist->items()->create([
        'position' => 0, 'type' => 'external', 'title' => $source->name, 'external_source_id' => $source->id,
    ]);
    addMusic($this->playlist, 1, 200);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    expect($runtime->offset($items[1]))->toBe(300)
        ->and($runtime->total())->toBe(500);
});

test('a container counts as the sum of its elements', function () {
    $container = $this->station->playlists()->create([
        'name' => 'Block', 'kind' => Playlist::KIND_CONTAINER, 'playback_mode' => 'sequential',
    ]);
    addMusic($container, 0, 30);
    addMusic($container, 1, 70);

    addMusic($this->playlist, 0, 200);
    $this->playlist->items()->create([
        'position' => 1, 'type' => 'container', 'title' => $container->name, 'container_playlist_id' => $container->id,
    ]);
    addMusic($this->playlist, 2, 60);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    expect($runtime->duration($items[1]))->toBe(100)
        ->and($runtime->offset($items[2]))->toBe(300)
        ->and($runtime->total())->toBe(360);
});

test('everything behind a fill element has no start time', function () {
    addMusic($this->playlist, 0, 200);
    $this->playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Auffüllen mit Musik']);
    addMusic($this->playlist, 2, 60);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    expect($runtime->offset($items[0]))->toBe(0)
        ->and($runtime->offset($items[1]))->toBe(200)
        ->and($runtime->offset($items[2]))->toBeNull()
        ->and($runtime->hasOpenEnd())->toBeTrue()
        ->and($runtime->unknownCount())->toBe(0)
        // Der bekannte Teil zaehlt weiter, auch hinter dem Fill.
        ->and($runtime->total())->toBe(260);
});

test('a random element is counted as unknown, not as open end', function () {
    addMusic($this->playlist, 0, 200);
    $this->playlist->items()->create(['position' => 1, 'type' => 'random', 'title' => 'Zufälliges Element']);

    $runtime = runtimeFor($this->playlist);

    expect($runtime->hasOpenEnd())->toBeFalse()
        ->and($runtime->unknownCount())->toBe(1);
});

test('a playlist longer than an hour is flagged', function () {
    addMusic($this->playlist, 0, 3500);
    addMusic($this->playlist, 1, 400);

    $runtime = runtimeFor($this->playlist);

    expect($runtime->total())->toBe(3900)
        ->and($runtime->fitsInHour())->toBeFalse()
        ->and($runtime->hourPercentage())->toBe(100);
});

test('lengths are formatted as mm:ss and h:mm:ss', function () {
    expect(PlaylistRuntime::format(65))->toBe('01:05')
        ->and(PlaylistRuntime::format(0))->toBe('00:00')
        ->and(PlaylistRuntime::format(3725))->toBe('1:02:05');
});
