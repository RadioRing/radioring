<?php

use App\Livewire\HourGrid\Index;
use App\Models\HourGridSlot;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->playlist = $this->station->playlists()->create([
        'name' => 'Morning Show',
        'playback_mode' => 'sequential',
    ]);
    $this->actingAs($this->user);
});

test('hour grid renders', function () {
    Livewire::test(Index::class)->assertOk();
});

test('user can assign a playlist to a slot', function () {
    Livewire::test(Index::class)
        ->call('editSlot', 0, 8)
        ->set('editingPlaylistId', $this->playlist->id)
        ->call('saveSlot');

    expect(HourGridSlot::where([
        'station_id' => $this->station->id,
        'weekday' => 0,
        'hour' => 8,
        'playlist_id' => $this->playlist->id,
    ])->exists())->toBeTrue();
});

test('user can clear a slot', function () {
    $this->station->hourGridSlots()->create([
        'weekday' => 2,
        'hour' => 14,
        'playlist_id' => $this->playlist->id,
    ]);

    Livewire::test(Index::class)
        ->call('editSlot', 2, 14)
        ->set('editingPlaylistId', null)
        ->call('saveSlot');

    expect(HourGridSlot::where(['station_id' => $this->station->id, 'weekday' => 2, 'hour' => 14])->exists())
        ->toBeFalse();
});

test('user can bulk assign a playlist to multiple slots', function () {
    Livewire::test(Index::class)
        ->call('assignMultiple', [
            ['weekday' => 0, 'hour' => 6],
            ['weekday' => 0, 'hour' => 7],
            ['weekday' => 0, 'hour' => 8],
        ], $this->playlist->id);

    expect(HourGridSlot::where('station_id', $this->station->id)->count())->toBe(3);
});

test('user cannot assign playlist from another station', function () {
    $otherStation = Station::factory()->create();
    $foreignPlaylist = $otherStation->playlists()->create([
        'name' => 'Foreign',
        'playback_mode' => 'sequential',
    ]);

    Livewire::test(Index::class)
        ->call('editSlot', 0, 10)
        ->set('editingPlaylistId', $foreignPlaylist->id)
        ->call('saveSlot')
        ->assertForbidden();
});

test('updating an existing slot replaces the playlist', function () {
    $this->station->hourGridSlots()->create([
        'weekday' => 1,
        'hour' => 9,
        'playlist_id' => $this->playlist->id,
    ]);

    $newPlaylist = $this->station->playlists()->create([
        'name' => 'Evening Show',
        'playback_mode' => 'random',
    ]);

    Livewire::test(Index::class)
        ->call('editSlot', 1, 9)
        ->set('editingPlaylistId', $newPlaylist->id)
        ->call('saveSlot');

    $slot = HourGridSlot::where(['station_id' => $this->station->id, 'weekday' => 1, 'hour' => 9])->first();
    expect($slot->playlist_id)->toBe($newPlaylist->id);
    expect(HourGridSlot::where(['station_id' => $this->station->id, 'weekday' => 1, 'hour' => 9])->count())->toBe(1);
});
