<?php

use App\Models\HourGridSlot;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

test('rundown generator passes legacy news/weather through as the matching source_type', function (string $type) {
    $this->playlist->items()->create([
        'position' => 0,
        'type' => $type,
        'title' => 'laut.fm Block',
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
    expect($item->source_type)->toBe($type)
        ->and($item->media_file_id)->toBeNull()
        ->and($item->duration_seconds)->toBe(config('radioring.news_duration_seconds', 300));
})->with(['news', 'weather', 'news_weather']);
