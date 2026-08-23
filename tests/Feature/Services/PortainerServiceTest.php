<?php

use App\Models\Station;
use App\Models\User;
use App\Services\PortainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.portainer.endpoint' => 'http://portainer.test/api',
        'services.portainer.token' => 'test-token',
        'services.portainer.environment' => '2',
        'radioring.station_image' => 'ghcr.io/acme/radioring-liquidsoap-station:latest',
        'radioring.liquidsoap_api_url' => 'https://radioring.app',
        'radioring.registry_username' => '',
        'radioring.registry_password' => '',
    ]);

    $this->station = Station::factory()->create(['user_id' => User::factory()]);
});

test('start pulls image, creates and starts the container', function () {
    Http::fake([
        '*/docker/images/create*' => Http::response('', 200),
        '*/docker/containers/create*' => Http::response(['Id' => 'abc123'], 201),
        '*/docker/containers/abc123/start' => Http::response('', 204),
    ]);

    $ok = app(PortainerService::class)->startStationContainer($this->station);

    expect($ok)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/endpoints/2/docker/images/create')
        && str_contains($request->url(), 'fromImage=ghcr.io/acme/radioring-liquidsoap-station')
        && str_contains($request->url(), 'tag=latest'));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/docker/containers/create?name=radioring-'.$this->station->slug)) {
            return false;
        }

        $env = $request->data()['Env'] ?? [];

        return in_array('SLUG='.$this->station->slug, $env, true)
            && in_array('TOKEN='.$this->station->api_token, $env, true)
            && in_array('API_URL=https://radioring.app', $env, true);
    });

    Http::assertSent(fn ($request) => str_contains($request->url(), '/docker/containers/abc123/start')
        && $request->header('X-API-Key')[0] === 'test-token');
});

test('start returns false when create has no id and container not found', function () {
    Http::fake([
        '*/docker/images/create*' => Http::response('', 200),
        '*/docker/containers/create*' => Http::response('conflict', 409),
        '*/docker/containers/*/json' => Http::response('not found', 404),
    ]);

    $ok = app(PortainerService::class)->startStationContainer($this->station);

    expect($ok)->toBeFalse();
});

test('stop deletes the container with force', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $ok = app(PortainerService::class)->stopStationContainer($this->station);

    expect($ok)->toBeTrue();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/docker/containers/radioring-'.$this->station->slug)
        && str_contains($request->url(), 'force=true'));
});

test('restart posts to the restart endpoint', function () {
    Http::fake(['*/restart' => Http::response('', 204)]);

    $ok = app(PortainerService::class)->restartStationContainer($this->station);

    expect($ok)->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/docker/containers/radioring-'.$this->station->slug.'/restart'));
});

test('container state parses docker status', function () {
    Http::fake(['*/json' => Http::response(['State' => ['Status' => 'Running']], 200)]);

    expect(app(PortainerService::class)->containerState($this->station))->toBe('running');
});

test('container state returns not_found on 404', function () {
    Http::fake(['*/json' => Http::response('', 404)]);

    expect(app(PortainerService::class)->containerState($this->station))->toBe('not_found');
});

test('start sends registry auth header when credentials configured', function () {
    config([
        'radioring.registry_username' => 'ghuser',
        'radioring.registry_password' => 'ghpass',
    ]);

    Http::fake([
        '*/docker/images/create*' => Http::response('', 200),
        '*/docker/containers/create*' => Http::response(['Id' => 'abc123'], 201),
        '*/docker/containers/abc123/start' => Http::response('', 204),
    ]);

    app(PortainerService::class)->startStationContainer($this->station);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/docker/images/create')) {
            return false;
        }

        $header = $request->header('X-Registry-Auth')[0] ?? '';
        $decoded = json_decode(base64_decode($header), true);

        return ($decoded['username'] ?? null) === 'ghuser'
            && ($decoded['serveraddress'] ?? null) === 'ghcr.io';
    });
});
