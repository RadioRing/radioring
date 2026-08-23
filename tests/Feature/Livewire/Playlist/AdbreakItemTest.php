<?php

use App\Livewire\Playlist\Manager;
use App\Models\HourGridSlot;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
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

test('user can add an adbreak item', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'adbreak')
        ->call('addItem');

    $item = $this->playlist->items()->first();
    expect($item->type)->toBe('adbreak')
        ->and($item->title)->toBe('Werbeunterbrechung (START_AD_BREAK)');
});

test('rundown generator passes adbreak through as adbreak source_type', function () {
    $this->playlist->items()->create([
        'position' => 0,
        'type' => 'adbreak',
        'title' => 'Werbeunterbrechung (START_AD_BREAK)',
    ]);

    $slot = HourGridSlot::factory()->create([
        'station_id' => $this->station->id,
        'playlist_id' => $this->playlist->id,
        'weekday' => 0,
        'hour' => 10,
    ]);

    $rundown = app(RundownGeneratorService::class)
        ->generate($this->station, $slot, Carbon::parse('2026-05-04'), true);

    $item = $rundown->items()->first();
    expect($item->source_type)->toBe('adbreak')
        ->and($item->title)->toBe('START_AD_BREAK')
        ->and($item->media_file_id)->toBeNull();
});
