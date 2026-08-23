<?php

use App\Models\Station;
use App\Models\StationStream;
use App\Services\PortainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['radioring.stream.port_min' => 8001, 'radioring.stream.port_max' => 8099]);
});

test('ensureStream provisions a port from the range and a password, idempotently', function () {
    $station = Station::factory()->create();

    $stream = $station->ensureStream();

    expect($stream->live_port)->toBeGreaterThanOrEqual(8001)->toBeLessThanOrEqual(8099);
    expect($stream->live_password)->not->toBeNull();

    $port = $stream->live_port;
    $password = $stream->live_password;

    expect($station->fresh()->ensureStream())
        ->live_port->toBe($port)
        ->live_password->toBe($password);
});

test('each station gets a distinct port', function () {
    $a = Station::factory()->create();
    $b = Station::factory()->create();

    expect($a->ensureStream()->live_port)->not->toBe($b->ensureStream()->live_port);
});

test('a duplicate or out-of-range port is reassigned', function () {
    $a = Station::factory()->create();
    $b = Station::factory()->create();

    // Beide künstlich auf denselben (gültigen) Port setzen.
    $a->stream()->create(['container_name' => 'radioring-'.$a->slug, 'live_port' => 8001]);
    $b->stream()->create(['container_name' => 'radioring-'.$b->slug, 'live_port' => 8001]);

    // Wer ensureStream zuerst aufruft, sieht den Konflikt und zieht um.
    $a->ensureStream();

    expect($a->fresh()->stream->live_port)->not->toBe(8001);
    expect(StationStream::where('live_port', $a->fresh()->stream->live_port)->count())->toBe(1);
});

test('liveStreamCredentials expose the host, assigned port and plaintext flag', function () {
    config(['radioring.stream.domain' => 'stream.radioring.de']);
    $station = Station::factory()->create(['slug' => 'mysender']);

    $creds = $station->liveStreamCredentials();

    expect($creds)->not->toBeNull();
    expect($creds['host'])->toBe('mysender.stream.radioring.de');
    expect($creds['port'])->toBe($station->fresh()->stream->live_port);
    expect($creds['mount'])->toBe('/live');
    expect($creds['username'])->toBe('source');
    expect($creds['password'])->not->toBeNull();
    expect($creds['tls'])->toBeFalse();
});

test('liveStreamCredentials are null without a configured domain', function () {
    config(['radioring.stream.domain' => '']);
    $station = Station::factory()->create();

    expect($station->liveStreamCredentials())->toBeNull();
});

test('container creation publishes the harbor port to the host when a domain is set', function () {
    config([
        'services.portainer.endpoint' => 'http://portainer',
        'services.portainer.token' => 'tok',
        'services.portainer.environment' => '1',
        'radioring.stream.domain' => 'stream.radioring.de',
    ]);

    Http::fake([
        '*/images/create*' => Http::response([], 200),
        '*/containers/create*' => Http::response(['Id' => 'abc123'], 201),
        '*/containers/*/start' => Http::response([], 204),
    ]);

    $station = Station::factory()->create(['slug' => 'mysender']);

    expect((new PortainerService)->startStationContainer($station))->toBeTrue();

    $port = $station->fresh()->stream->live_port;

    Http::assertSent(function ($request) use ($port) {
        if (! str_contains($request->url(), '/containers/create')) {
            return false;
        }

        $bindings = $request->data()['HostConfig']['PortBindings'] ?? [];

        return isset($bindings["{$port}/tcp"])
            && ($bindings["{$port}/tcp"][0]['HostPort'] ?? null) === (string) $port
            && array_key_exists("{$port}/tcp", $request->data()['ExposedPorts'] ?? [])
            && ! array_key_exists('traefik.enable', $request->data()['Labels'] ?? []);
    });
});

test('container creation does not publish a port without a stream domain', function () {
    config([
        'services.portainer.endpoint' => 'http://portainer',
        'services.portainer.token' => 'tok',
        'services.portainer.environment' => '1',
        'radioring.stream.domain' => '',
    ]);

    Http::fake([
        '*/images/create*' => Http::response([], 200),
        '*/containers/create*' => Http::response(['Id' => 'abc123'], 201),
        '*/containers/*/start' => Http::response([], 204),
    ]);

    $station = Station::factory()->create();

    (new PortainerService)->startStationContainer($station);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/containers/create')) {
            return false;
        }

        return ! array_key_exists('PortBindings', $request->data()['HostConfig'] ?? [])
            && ! array_key_exists('ExposedPorts', $request->data());
    });
});
