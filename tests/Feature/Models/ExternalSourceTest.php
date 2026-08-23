<?php

use App\Models\ExternalSource;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a url source resolves to its configured url', function () {
    $source = ExternalSource::factory()->create(['kind' => 'url', 'url' => 'https://example.com/show.mp3']);

    expect($source->resolveUrl())->toBe('https://example.com/show.mp3');
});

test('a news source builds the laut.fm url from the station output credentials', function () {
    $station = Station::factory()->create();
    $station->outputs()->create([
        'type' => 'lautfm',
        'host' => 'stream.laut.fm',
        'port' => 80,
        'mount' => '/teststation',
        'username' => 'teststation',
        'password' => 'geheim123',
        'bitrate' => 128,
        'enabled' => true,
    ]);

    $source = ExternalSource::factory()->news()->create(['station_id' => $station->id]);

    expect($source->resolveUrl())->toBe('https://teststation:geheim123@api.radioadmin.laut.fm/news/2');
});

test('a news source without laut.fm credentials resolves to null', function () {
    $source = ExternalSource::factory()->news()->create();

    expect($source->resolveUrl())->toBeNull();
});

test('usageCount reflects referencing playlist items', function () {
    $station = Station::factory()->create();
    $source = ExternalSource::factory()->create(['station_id' => $station->id]);
    $playlist = $station->playlists()->create(['name' => 'P', 'playback_mode' => 'sequential']);

    $playlist->items()->create(['position' => 0, 'type' => 'external', 'title' => 'Syndi', 'external_source_id' => $source->id]);

    expect($source->usageCount())->toBe(1);
});
