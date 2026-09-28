<?php

use App\Livewire\Playlist\Index;
use App\Models\HourGridSlot;
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

    $this->morning = $this->station->playlists()->create(['name' => 'Morning Show', 'playback_mode' => 'sequential']);
    $this->evening = $this->station->playlists()->create(['name' => 'Evening Mix', 'playback_mode' => 'random']);
    $this->news = $this->station->playlists()->create(['name' => 'News block', 'kind' => Playlist::KIND_CONTAINER, 'playback_mode' => 'sequential']);
});

test('playlists are sorted by name by default', function () {
    Livewire::test(Index::class)
        ->assertViewHas('playlists', fn ($playlists) => $playlists->pluck('name')->all() === ['Evening Mix', 'Morning Show']);
});

test('playlists can be sorted by the last change', function () {
    $this->morning->forceFill(['updated_at' => now()->addMinute()])->save();

    Livewire::test(Index::class)
        ->set('sort', 'recent')
        ->assertViewHas('playlists', fn ($playlists) => $playlists->pluck('name')->all() === ['Morning Show', 'Evening Mix']);
});

test('the search narrows playlists and containers', function () {
    Livewire::test(Index::class)
        ->set('search', 'morn')
        ->assertViewHas('playlists', fn ($playlists) => $playlists->pluck('name')->all() === ['Morning Show'])
        ->assertViewHas('containers', fn ($containers) => $containers->isEmpty())
        ->assertViewHas('totalPlaylists', 2);
});

test('search, tab and sort are kept in the url', function () {
    Livewire::withQueryParams(['q' => 'news', 'tab' => 'containers', 'sort' => 'recent'])
        ->test(Index::class)
        ->assertSet('search', 'news')
        ->assertSet('tab', 'containers')
        ->assertSet('sort', 'recent')
        ->assertSee('News block');
});

test('an unknown tab falls back to playlists', function () {
    Livewire::test(Index::class)
        ->call('switchTab', 'bogus')
        ->assertSet('tab', 'playlists');
});

test('the list tells how many hours of the grid play a playlist', function () {
    HourGridSlot::create(['station_id' => $this->station->id, 'playlist_id' => $this->morning->id, 'weekday' => 0, 'hour' => 6]);
    HourGridSlot::create(['station_id' => $this->station->id, 'playlist_id' => $this->morning->id, 'weekday' => 1, 'hour' => 6]);

    Livewire::test(Index::class)
        ->assertViewHas('playlists', fn ($playlists) => $playlists->firstWhere('id', $this->morning->id)->hour_grid_slots_count === 2
            && $playlists->firstWhere('id', $this->evening->id)->hour_grid_slots_count === 0)
        ->assertSee('2 hours on the grid')
        ->assertSee('not on the hour grid');
});

test('the create dialog offers the current search as name', function () {
    Livewire::test(Index::class)
        ->set('search', 'Late Night')
        ->call('startCreating', 'playlist')
        ->assertSet('showCreateForm', true)
        ->assertSet('newName', 'Late Night')
        ->assertDispatched('playlist-create-opened');
});

test('creating a playlist opens it in the editor', function () {
    Livewire::test(Index::class)
        ->call('startCreating', 'playlist')
        ->set('newName', 'Late Night')
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('playlist-create-closed')
        ->assertRedirect(route('playlist.manager', $this->station->playlists()->where('name', 'Late Night')->first()));
});

test('cancelling the create dialog resets it', function () {
    Livewire::test(Index::class)
        ->call('startCreating', 'container')
        ->set('newName', 'Half typed')
        ->call('cancelCreating')
        ->assertSet('showCreateForm', false)
        ->assertSet('newName', '')
        ->assertSet('newKind', Playlist::KIND_PLAYLIST)
        ->assertDispatched('playlist-create-closed');
});

test('a duplicate is highlighted in the list', function () {
    $component = Livewire::test(Index::class)->call('duplicate', $this->morning->id);

    $copy = $this->station->playlists()->where('name', 'like', 'Morning Show %')->first();

    $component->assertSet('highlightId', $copy->id)
        ->assertDispatched('playlist-row-highlighted', id: $copy->id);
});
