<?php

use App\Livewire\HourGrid\Index as HourGridIndex;
use App\Livewire\Playlist\Index as PlaylistIndex;
use App\Livewire\Playlist\Manager;
use App\Models\HourGridSlot;
use App\Models\Playlist;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->playlist = $this->station->playlists()->create([
        'name' => 'Test Playlist',
        'playback_mode' => 'sequential',
    ]);
    $this->actingAs($this->user);
});

/** Creates a container with one jingle in it. */
function makeContainer(Station $station, string $name = 'News block'): Playlist
{
    $container = $station->playlists()->create([
        'name' => $name,
        'kind' => Playlist::KIND_CONTAINER,
        'playback_mode' => 'sequential',
    ]);

    $jingle = $station->mediaFiles()->create([
        'title' => 'News Jingle',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/news-jingle.mp3',
        'duration_seconds' => 10,
    ]);

    $container->items()->create([
        'position' => 0,
        'type' => 'jingle',
        'title' => 'News Jingle',
        'media_file_id' => $jingle->id,
    ]);

    return $container;
}

test('user can create a container', function () {
    Livewire::test(PlaylistIndex::class)
        ->call('startCreating', 'container')
        ->set('newName', 'News block')
        ->call('create');

    $container = $this->station->playlists()->where('name', 'News block')->first();

    expect($container)->not->toBeNull()
        ->and($container->kind)->toBe(Playlist::KIND_CONTAINER)
        ->and($container->isContainer())->toBeTrue();
});

test('containers are listed apart from playlists', function () {
    makeContainer($this->station);

    Livewire::test(PlaylistIndex::class)
        ->assertViewHas('playlists', fn ($playlists) => $playlists->pluck('name')->all() === ['Test Playlist'])
        ->assertViewHas('containers', fn ($containers) => $containers->pluck('name')->all() === ['News block']);
});

test('containers cannot be scheduled on the hour grid', function () {
    $container = makeContainer($this->station);

    Livewire::test(HourGridIndex::class)
        ->assertViewHas('playlists', fn ($playlists) => ! $playlists->contains('id', $container->id));

    Livewire::test(HourGridIndex::class)
        ->call('assignMultiple', [['weekday' => 0, 'hour' => 10]], $container->id)
        ->assertForbidden();
});

test('user can add a container to a playlist', function () {
    $container = makeContainer($this->station);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'container')
        ->set('selectedContainerId', $container->id)
        ->call('addItem');

    $item = $this->playlist->items()->first();

    expect($item->type)->toBe('container')
        ->and($item->container_playlist_id)->toBe($container->id)
        ->and($item->title)->toBe('News block');
});

test('a container from another station is rejected', function () {
    $otherStation = Station::factory()->create();
    $foreign = makeContainer($otherStation, 'Foreign block');

    expect(fn () => Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'container')
        ->set('selectedContainerId', $foreign->id)
        ->call('addItem'))->toThrow(ModelNotFoundException::class);

    expect($this->playlist->items()->count())->toBe(0);
});

test('a container cannot be nested into another container', function () {
    $container = makeContainer($this->station);
    $other = makeContainer($this->station, 'Other block');

    Livewire::test(Manager::class, ['playlist' => $other])
        ->set('newType', 'container')
        ->set('selectedContainerId', $container->id)
        ->call('addItem')
        ->assertForbidden();
});

test('deleting a container also removes it from the playlists using it', function () {
    $container = makeContainer($this->station);
    $this->playlist->items()->create([
        'position' => 0,
        'type' => 'container',
        'title' => $container->name,
        'container_playlist_id' => $container->id,
    ]);

    Livewire::test(PlaylistIndex::class)->call('delete', $container->id);

    expect($this->station->playlists()->find($container->id))->toBeNull()
        ->and($this->playlist->items()->count())->toBe(0);
});

test('only the name is editable on a container', function () {
    $container = makeContainer($this->station);

    Livewire::test(Manager::class, ['playlist' => $container])
        ->set('name', 'Renamed block')
        ->set('startMode', 'hard')
        ->call('saveSettings');

    expect($container->fresh()->name)->toBe('Renamed block')
        ->and($container->fresh()->start_mode)->not->toBe('hard');
});

test('rundown generation expands the container in place', function () {
    $container = makeContainer($this->station);
    $container->items()->create([
        'position' => 1,
        'type' => 'adbreak',
        'title' => 'Werbeunterbrechung (START_AD_BREAK)',
    ]);

    $song = $this->station->mediaFiles()->create([
        'title' => 'Opener',
        'type' => 'music',
        'file_path' => 'tenants/test/media/opener.mp3',
        'duration_seconds' => 200,
    ]);

    $this->playlist->items()->create([
        'position' => 0,
        'type' => 'music',
        'title' => 'Opener',
        'media_file_id' => $song->id,
    ]);
    $this->playlist->items()->create([
        'position' => 1,
        'type' => 'container',
        'title' => $container->name,
        'container_playlist_id' => $container->id,
    ]);
    $this->playlist->items()->create([
        'position' => 2,
        'type' => 'music',
        'title' => 'Closer',
        'media_file_id' => $song->id,
    ]);

    $slot = HourGridSlot::factory()->create([
        'station_id' => $this->station->id,
        'playlist_id' => $this->playlist->id,
        'weekday' => 0,
        'hour' => 10,
    ]);

    $rundown = app(RundownGeneratorService::class)
        ->generate($this->station, $slot, Carbon::parse('2026-05-04'), true);

    expect($rundown->items->pluck('title')->all())
        ->toBe(['Opener', 'News Jingle', 'START_AD_BREAK', 'Closer'])
        ->and($rundown->items->pluck('position')->all())->toBe([0, 1, 2, 3])
        ->and($rundown->items->where('source_type', 'container')->count())->toBe(0);

    // Broadcast times run through without a gap at the container boundary.
    expect($rundown->items[1]->absolute_broadcast_at->format('H:i:s'))->toBe('10:03:20')
        ->and($rundown->items[3]->absolute_broadcast_at->format('H:i:s'))->toBe('10:03:30');
});

test('a broken container reference is skipped during generation', function () {
    $this->playlist->items()->create([
        'position' => 0,
        'type' => 'container',
        'title' => 'Gone',
        'container_playlist_id' => null,
    ]);

    $slot = HourGridSlot::factory()->create([
        'station_id' => $this->station->id,
        'playlist_id' => $this->playlist->id,
        'weekday' => 0,
        'hour' => 10,
    ]);

    $rundown = app(RundownGeneratorService::class)
        ->generate($this->station, $slot, Carbon::parse('2026-05-04'), true);

    expect($rundown->items)->toHaveCount(0);
});

test('timestamps inside a container are ignored', function () {
    $container = makeContainer($this->station);
    $container->items()->first()->update(['relative_offset_seconds' => 1800]);

    $this->playlist->items()->create([
        'position' => 0,
        'type' => 'container',
        'title' => $container->name,
        'container_playlist_id' => $container->id,
    ]);

    $slot = HourGridSlot::factory()->create([
        'station_id' => $this->station->id,
        'playlist_id' => $this->playlist->id,
        'weekday' => 0,
        'hour' => 10,
    ]);

    $rundown = app(RundownGeneratorService::class)
        ->generate($this->station, $slot, Carbon::parse('2026-05-04'), true);

    expect($rundown->items->first()->absolute_broadcast_at->format('H:i:s'))->toBe('10:00:00');
});
