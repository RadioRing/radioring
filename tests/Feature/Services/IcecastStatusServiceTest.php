<?php

use App\Models\Station;
use App\Models\User;
use App\Services\IcecastStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'radioring.stream.domain' => 'stream.example.com',
        'radioring.icecast.traefik_enabled' => true,
        'radioring.icecast.status_ttl_seconds' => 10,
    ]);

    Cache::flush();

    $this->station = Station::factory()->create([
        'user_id' => User::factory(),
        'slug' => 'mysender',
    ]);
});

function addInternalOutput(Station $station, string $mount = '/stream'): void
{
    $station->outputs()->create([
        'type' => 'internal',
        'host' => $station->icecastContainerName(),
        'port' => 8000,
        'mount' => $mount,
        'username' => 'source',
        'bitrate' => 128,
        'enabled' => true,
    ]);
}

test('no internal output means no listener figure at all', function () {
    Http::fake();

    expect(app(IcecastStatusService::class)->listeners($this->station))->toBeNull();

    Http::assertNothingSent();
});

/**
 * Captured from a running Icecast 2.4.4 of the sidecar image. With exactly one mount
 * "source" is an object, with several a list. That shape change is why the service
 * branches, so the real payload belongs in the test.
 */
function icecastStatusJson(bool $secondMount): array
{
    $stream = [
        'genre' => 'various',
        'listener_peak' => 1,
        'listeners' => 7,
        'listenurl' => 'http://mysender.stream.example.com:8000/stream',
        'server_description' => 'Unspecified description',
        'server_name' => 'Testsender',
        'server_type' => 'audio/mpeg',
        'stream_start_iso8601' => '2026-08-24T12:36:12+0000',
        'dummy' => null,
    ];

    $other = [
        'genre' => 'various',
        'listener_peak' => 0,
        'listeners' => 99,
        'listenurl' => 'http://mysender.stream.example.com:8000/second',
        'server_name' => 'Zweiter',
        'server_type' => 'audio/mpeg',
        'dummy' => null,
    ];

    return ['icestats' => [
        'admin' => 'noreply@localhost',
        'host' => 'mysender.stream.example.com',
        'location' => 'RadioRing',
        'server_id' => 'Icecast 2.4.4',
        'source' => $secondMount ? [$other, $stream] : $stream,
    ]];
}

test('listeners are read from the sidecar over the container name', function () {
    addInternalOutput($this->station);

    Http::fake(['*' => Http::response(icecastStatusJson(secondMount: false))]);

    expect(app(IcecastStatusService::class)->listeners($this->station))->toBe(7);

    Http::assertSent(fn ($request) => $request->url() === 'http://radioring-icecast-mysender:8000/status-json.xsl');
});

test('the mount is picked out when several sources are listed', function () {
    addInternalOutput($this->station, '/stream');

    Http::fake(['*' => Http::response(icecastStatusJson(secondMount: true))]);

    expect(app(IcecastStatusService::class)->listeners($this->station))->toBe(7);
});

test('a running sidecar without any source reports zero, not null', function () {
    addInternalOutput($this->station);

    // Exactly what a freshly started Icecast returns while nobody is sending: the
    // "source" key is missing entirely.
    Http::fake(['*' => Http::response(['icestats' => [
        'admin' => 'noreply@localhost',
        'host' => 'mysender.stream.example.com',
        'server_id' => 'Icecast 2.4.4',
        'dummy' => null,
    ]])]);

    expect(app(IcecastStatusService::class)->listeners($this->station))->toBe(0);
});

test('an unreachable sidecar reports no data instead of failing', function () {
    addInternalOutput($this->station);

    Http::fake(['*' => Http::response('', 502)]);

    expect(app(IcecastStatusService::class)->listeners($this->station))->toBeNull();
});

test('the figure is cached so a polling dashboard does not hammer the sidecar', function () {
    addInternalOutput($this->station);

    Http::fake(['*' => Http::response(['icestats' => ['source' => [
        'listenurl' => 'http://x:8000/stream',
        'listeners' => 4,
    ]]])]);

    $service = app(IcecastStatusService::class);

    expect($service->listeners($this->station))->toBe(4);
    expect($service->listeners($this->station))->toBe(4);

    Http::assertSentCount(1);
});
