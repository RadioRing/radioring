<?php

use App\Models\ExternalSource;
use App\Models\Playlist;
use App\Models\PlaylistItem;
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
        ->get(), test()->station);
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

test('a fill with nothing behind it runs up to the full hour', function () {
    addMusic($this->playlist, 0, 200);
    $fill = $this->playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Auffüllen mit Musik']);
    addMusic($this->playlist, 2, 60);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    expect($runtime->duration($fill))->toBe(3340)
        ->and($runtime->fillTarget($fill))->toBe(3600)
        ->and($runtime->offset($items[2]))->toBe(3540)
        ->and($runtime->offsetIsApproximate($items[2]))->toBeTrue()
        ->and($runtime->hasOpenEnd())->toBeFalse()
        ->and($runtime->total())->toBe(3600);
});

test('a fill inside a container keeps an open end up to its budget', function () {
    addMusic($this->playlist, 0, 200);
    // Ohne eigene Maximaldauer bekommt der Generator 3600 Sekunden als Budget.
    $this->playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Auffüllen mit Musik']);
    $this->playlist->items()->create([
        'position' => 2, 'type' => 'fill', 'title' => 'Auffüllen mit Musik', 'fill_max_duration_seconds' => 600,
    ]);

    $runtime = PlaylistRuntime::for($this->playlist->items()->orderBy('position')->get(), $this->station, isContainer: true);
    $items = $this->playlist->items()->orderBy('position')->get();

    expect($runtime->hasOpenEnd())->toBeTrue()
        ->and($runtime->fillBudget())->toBe(4200)
        ->and($runtime->offset($items[2]))->toBeNull();
});

test('a playlist without a fill element has no fill budget', function () {
    addMusic($this->playlist, 0, 200);

    expect(runtimeFor($this->playlist)->fillBudget())->toBe(0);
});

test('a random element counts with the average length of its pool', function () {
    addMusic($this->playlist, 0, 200);
    addMusic($this->playlist, 1, 100);
    $random = $this->playlist->items()->create(['position' => 2, 'type' => 'random', 'title' => 'Zufälliges Element']);
    addMusic($this->playlist, 3, 60);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    // Pool: 200, 100 and 60 s.
    expect($runtime->duration($random))->toBe(120)
        ->and($runtime->durationIsApproximate($random))->toBeTrue()
        ->and($runtime->offset($items[3]))->toBe(420)
        ->and($runtime->offsetIsApproximate($items[3]))->toBeTrue()
        ->and($runtime->totalIsApproximate())->toBeTrue()
        ->and($runtime->unknownCount())->toBe(0);
});

test('a random element without a pool is counted as unknown, not as open end', function () {
    addMusic($this->playlist, 0, 200);
    $tag = $this->station->tags()->create(['name' => 'Leer']);
    $this->playlist->items()->create(['position' => 1, 'type' => 'random', 'title' => 'Zufälliges Element', 'fill_tags' => [$tag->id]]);

    $runtime = runtimeFor($this->playlist);

    expect($runtime->hasOpenEnd())->toBeFalse()
        ->and($runtime->unknownCount())->toBe(1);
});

function addRuntimeMarker(Playlist $playlist, int $position, int $at, string $mode = 'soft'): PlaylistItem
{
    return $playlist->items()->create([
        'position' => $position, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => $at, 'fixed_mode' => $mode,
    ]);
}

test('a fill in front of a soft fixed time runs up to it', function () {
    addMusic($this->playlist, 0, 200);
    $fill = $this->playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Auffüllen mit Musik']);
    $marker = addRuntimeMarker($this->playlist, 2, 900);
    $adbreak = $this->playlist->items()->create(['position' => 3, 'type' => 'adbreak', 'title' => 'Werbung']);
    addMusic($this->playlist, 4, 100);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    expect($runtime->duration($fill))->toBe(700)
        ->and($runtime->durationIsApproximate($fill))->toBeTrue()
        ->and($runtime->fillTarget($fill))->toBe(900)
        ->and($runtime->offset($marker))->toBe(900)
        ->and($runtime->markerDeviation($marker))->toBe(0)
        ->and($runtime->offset($adbreak))->toBe(900)
        ->and($runtime->offsetIsApproximate($adbreak))->toBeTrue()
        ->and($runtime->offset($items[4]))->toBe(900)
        ->and($runtime->hasOpenEnd())->toBeFalse()
        ->and($runtime->total())->toBe(1000);
});

test('a soft fixed time picks the chain up again behind an unknown length', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'expected_duration_seconds' => null,
    ]);
    $this->playlist->items()->create([
        'position' => 0, 'type' => 'external', 'title' => $source->name, 'external_source_id' => $source->id,
    ]);
    addMusic($this->playlist, 1, 60);
    $marker = addRuntimeMarker($this->playlist, 2, 1800);
    addMusic($this->playlist, 3, 60);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    expect($runtime->offset($items[1]))->toBeNull()
        ->and($runtime->markerDeviation($marker))->toBeNull()
        ->and($runtime->offset($items[3]))->toBe(1800)
        ->and($runtime->offsetIsApproximate($items[3]))->toBeTrue();
});

test('a hard fixed time sets the clock to the second', function () {
    addMusic($this->playlist, 0, 1000);
    $marker = addRuntimeMarker($this->playlist, 1, 900, 'hard');
    addMusic($this->playlist, 2, 60);

    $runtime = runtimeFor($this->playlist);
    $items = $this->playlist->items()->orderBy('position')->get();

    // 100 s overrun, cut.
    expect($runtime->markerDeviation($marker))->toBe(100)
        ->and($runtime->offset($items[2]))->toBe(900)
        ->and($runtime->offsetIsApproximate($items[2]))->toBeFalse();
});

test('a hard fixed time without a fill in front shows the gap', function () {
    addMusic($this->playlist, 0, 600);
    $marker = addRuntimeMarker($this->playlist, 1, 900, 'hard');

    expect(runtimeFor($this->playlist)->markerDeviation($marker))->toBe(-300);
});

test('a container playlist ignores fixed times', function () {
    addMusic($this->playlist, 0, 200);
    $fill = $this->playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Auffüllen mit Musik']);
    addRuntimeMarker($this->playlist, 2, 900, 'hard');

    $runtime = PlaylistRuntime::for($this->playlist->items()->orderBy('position')->get(), $this->station, isContainer: true);

    expect($runtime->fillTarget($fill))->toBeNull()
        ->and($runtime->hasOpenEnd())->toBeTrue();
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
