<?php

use App\Livewire\Playlist\Index;
use App\Livewire\Playlist\Manager;
use App\Models\Playlist;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

test('a new playlist starts without fixed times', function () {
    Livewire::test(Index::class)
        ->set('newName', 'Morning Show')
        ->call('create');

    $playlist = $this->station->playlists()->where('name', 'Morning Show')->first();

    expect($playlist->items()->count())->toBe(0)
        ->and($playlist->startsHard())->toBeFalse();
});

test('a marker from the palette is added at 00:00 soft and opened for editing', function () {
    $playlist = $this->station->playlists()->create(['name' => 'Show', 'playback_mode' => 'sequential']);

    $component = Livewire::test(Manager::class, ['playlist' => $playlist])
        ->call('insertEntry', 'special:marker');

    $marker = $playlist->items()->first();

    expect($marker->type)->toBe('marker')
        ->and($marker->relative_offset_seconds)->toBe(0)
        ->and($marker->fixed_mode)->toBe('soft');

    $component->assertSet('editingItemId', $marker->id)
        ->assertSet('editRelativeOffset', '00:00');
});

test('a hard 00:00 marker on top is the hard start on the hour', function () {
    $playlist = $this->station->playlists()->create(['name' => 'Top of Hour', 'playback_mode' => 'sequential']);

    Livewire::test(Manager::class, ['playlist' => $playlist])
        ->call('insertEntry', 'special:marker')
        ->set('editFixedMode', 'hard')
        ->call('saveItem');

    expect($playlist->fresh()->startsHard())->toBeTrue();
});

test('a hard marker later in the hour is no hard start on the hour', function () {
    $playlist = $this->station->playlists()->create(['name' => 'Show', 'playback_mode' => 'sequential']);
    $playlist->items()->create(['position' => 0, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => 1800, 'fixed_mode' => 'hard']);

    expect($playlist->startsHard())->toBeFalse();
});

test('a marker cannot be added to a container', function () {
    $container = $this->station->playlists()->create([
        'name' => 'Block', 'kind' => Playlist::KIND_CONTAINER, 'playback_mode' => 'sequential',
    ]);

    Livewire::test(Manager::class, ['playlist' => $container])
        ->call('insertEntry', 'special:marker')
        ->assertDispatched('notify', type: 'warning');

    expect($container->items()->count())->toBe(0);
});
