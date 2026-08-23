<?php

use App\Models\ExternalSource;
use App\Models\Station;
use App\Models\StationOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create();
});

function lautfmOutputWithMount(Station $station, string $mount): StationOutput
{
    return $station->outputs()->create([
        'type' => 'lautfm',
        'host' => 'stream.laut.fm',
        'port' => 80,
        'mount' => $mount,
        'username' => 'source',
        'password' => 'geheim123',
        'bitrate' => 128,
        'enabled' => true,
    ]);
}

test('the mount name drops the leading slash', function () {
    $output = lautfmOutputWithMount($this->station, '/syndications4radio');

    expect($output->mountName())->toBe('syndications4radio');
});

test('the mount name drops icecast query parameters', function (string $mount) {
    $output = lautfmOutputWithMount($this->station, $mount);

    expect($output->mountName())->toBe('syndications4radio');
})->with([
    '/syndications4radio?prio=3',
    'syndications4radio?prio=3',
    '/syndications4radio?prio=3&foo=bar',
    '/syndications4radio#anchor',
]);

test('the outgoing icecast mount keeps its parameters', function () {
    $output = lautfmOutputWithMount($this->station, '/syndications4radio?prio=3');

    // Der Stream-Ausgang braucht den Parameter, nur die API-Credentials nicht.
    expect($output->mount)->toBe('/syndications4radio?prio=3');
});

test('a mount with a query still yields a valid news url', function () {
    lautfmOutputWithMount($this->station, '/syndications4radio?prio=3');

    $source = ExternalSource::create([
        'station_id' => $this->station->id,
        'name' => 'Nachrichten',
        'kind' => 'news',
    ]);

    $url = $source->resolveUrl();
    $parts = parse_url($url);

    expect($parts['host'])->toBe('api.radioadmin.laut.fm')
        ->and($parts['user'])->toBe('syndications4radio')
        ->and($parts['pass'])->toBe('geheim123')
        ->and($parts['path'])->toBe('/news/2')
        ->and($parts)->not->toHaveKey('query');
});

test('news, weather and combined map to their endpoint segments', function (string $kind, string $path) {
    lautfmOutputWithMount($this->station, '/syndications4radio?prio=3');

    $source = ExternalSource::create([
        'station_id' => $this->station->id,
        'name' => 'Quelle',
        'kind' => $kind,
    ]);

    expect(parse_url($source->resolveUrl(), PHP_URL_PATH))->toBe($path);
})->with([
    ['news_weather', '/news/1'],
    ['news', '/news/2'],
    ['weather', '/news/3'],
]);

test('a password with special characters survives the url', function () {
    lautfmOutputWithMount($this->station, '/syndications4radio?prio=3')
        ->update(['password' => 'p@ss:wo/rd?x']);

    $source = ExternalSource::create([
        'station_id' => $this->station->id,
        'name' => 'Nachrichten',
        'kind' => 'news',
    ]);

    $parts = parse_url($source->resolveUrl());

    expect($parts['host'])->toBe('api.radioadmin.laut.fm')
        ->and(rawurldecode($parts['pass']))->toBe('p@ss:wo/rd?x');
});

test('no laut.fm output means no url', function () {
    $source = ExternalSource::create([
        'station_id' => $this->station->id,
        'name' => 'Nachrichten',
        'kind' => 'news',
    ]);

    expect($source->resolveUrl())->toBeNull();
});
