<?php

use App\Livewire\Playlist\Index;
use App\Livewire\Playlist\Manager;
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

test('start_mode defaults to soft for new playlists', function () {
    Livewire::test(Index::class)
        ->set('newName', 'Morning Show')
        ->call('create');

    expect($this->station->playlists()->where('name', 'Morning Show')->value('start_mode'))->toBe('soft');
});

test('user can create a hard-start playlist', function () {
    Livewire::test(Index::class)
        ->set('newName', 'Top of Hour')
        ->set('newStartMode', 'hard')
        ->call('create');

    expect($this->station->playlists()->where('name', 'Top of Hour')->value('start_mode'))->toBe('hard');
});

test('user can change the start_mode of a playlist', function () {
    $playlist = $this->station->playlists()->create([
        'name' => 'Show',
        'playback_mode' => 'sequential',
    ]);

    Livewire::test(Manager::class, ['playlist' => $playlist])
        ->set('startMode', 'hard')
        ->call('saveSettings');

    expect($playlist->fresh()->start_mode)->toBe('hard');
});

test('start_mode must be soft or hard', function () {
    $playlist = $this->station->playlists()->create([
        'name' => 'Show',
        'playback_mode' => 'sequential',
    ]);

    Livewire::test(Manager::class, ['playlist' => $playlist])
        ->set('startMode', 'invalid')
        ->call('saveSettings')
        ->assertHasErrors('startMode');
});
