<?php

use App\Contracts\ContainerServiceInterface;
use App\Models\Station;
use App\Models\User;
use App\Services\DockerService;
use App\Services\PortainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'radioring.container_driver' => 'docker',
        'radioring.docker.host' => 'tcp://dockerproxy:2375',
        'radioring.docker.api_version' => 'v1.43',
        'radioring.docker.station_network' => '',
        'radioring.station_image' => 'ghcr.io/acme/radioring-liquidsoap-station:latest',
        'radioring.liquidsoap_api_url' => 'https://radioring.app',
        'radioring.registry_username' => '',
        'radioring.registry_password' => '',
        'radioring.stream.domain' => '',
    ]);

    $this->station = Station::factory()->create(['user_id' => User::factory()]);
});

function fakeSuccessfulDocker(): void
{
    Http::fake([
        '*/images/create*' => Http::response('{"status":"Pulling"}'."\n".'{"status":"Downloaded"}', 200),
        '*/containers/create*' => Http::response(['Id' => 'abc123'], 201),
        '*/containers/abc123/start' => Http::response('', 204),
    ]);
}

// -- Treiberauswahl ---------------------------------------------------------

test('the container driver is resolved from config', function (string $driver, string $expected) {
    config(['radioring.container_driver' => $driver]);

    expect(app(ContainerServiceInterface::class))->toBeInstanceOf($expected);
})->with([
    ['docker', DockerService::class],
    ['portainer', PortainerService::class],
    ['nonsense', DockerService::class],
]);

// -- isConfigured -----------------------------------------------------------

test('a tcp host counts as configured', function () {
    expect(app(DockerService::class)->isConfigured())->toBeTrue();
});

test('an empty host is not configured', function () {
    config(['radioring.docker.host' => '']);

    expect(app(DockerService::class)->isConfigured())->toBeFalse();
});

test('a socket path that does not exist is not configured', function () {
    config(['radioring.docker.host' => 'unix:///definitely/not/here.sock']);

    expect(app(DockerService::class)->isConfigured())->toBeFalse();
});

// -- Start ------------------------------------------------------------------

test('start pulls the image, creates and starts the container', function () {
    fakeSuccessfulDocker();

    expect(app(DockerService::class)->startStationContainer($this->station))->toBeTrue();

    // Kein Portainer-Praefix und kein API-Key mehr.
    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/endpoints/')
        && ! $request->hasHeader('X-API-Key'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'http://dockerproxy:2375/v1.43/images/create')
        && str_contains($request->url(), 'tag=latest'));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1.43/containers/create?name=radioring-'.$this->station->slug)) {
            return false;
        }

        $env = $request->data()['Env'] ?? [];
        $labels = $request->data()['Labels'] ?? [];

        return in_array('SLUG='.$this->station->slug, $env, true)
            && in_array('TOKEN='.$this->station->api_token, $env, true)
            && in_array('API_URL=https://radioring.app', $env, true)
            && ($labels['station_slug'] ?? null) === $this->station->slug
            && ! isset($request->data()['NetworkingConfig']);
    });
});

test('the station container joins the configured network', function () {
    config(['radioring.docker.station_network' => 'radioring']);
    fakeSuccessfulDocker();

    app(DockerService::class)->startStationContainer($this->station);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/containers/create')) {
            return false;
        }

        return array_key_exists('radioring', $request->data()['NetworkingConfig']['EndpointsConfig'] ?? []);
    });
});

test('the harbor port is published only when a stream domain is set', function () {
    config(['radioring.stream.domain' => 'stream.example.com']);
    fakeSuccessfulDocker();

    app(DockerService::class)->startStationContainer($this->station);

    $port = $this->station->fresh()->stream->live_port;

    Http::assertSent(function ($request) use ($port) {
        if (! str_contains($request->url(), '/containers/create')) {
            return false;
        }

        return ($request->data()['HostConfig']['PortBindings']["{$port}/tcp"][0]['HostPort'] ?? null) === (string) $port;
    });
});

test('a 304 on start is treated as already running', function () {
    Http::fake([
        '*/images/create*' => Http::response('', 200),
        '*/containers/create*' => Http::response(['Id' => 'abc123'], 201),
        '*/containers/abc123/start' => Http::response('', 304),
    ]);

    expect(app(DockerService::class)->startStationContainer($this->station))->toBeTrue();
});

test('a name conflict falls back to the existing container', function () {
    Http::fake([
        '*/images/create*' => Http::response('', 200),
        '*/containers/create*' => Http::response(['message' => 'Conflict'], 409),
        '*/containers/radioring-*/json' => Http::response(['Id' => 'existing'], 200),
        '*/containers/existing/start' => Http::response('', 204),
    ]);

    expect(app(DockerService::class)->startStationContainer($this->station))->toBeTrue();
});

test('a failed create logs the message docker returned', function () {
    Log::spy();

    Http::fake([
        '*/images/create*' => Http::response('', 200),
        '*/containers/create*' => Http::response(['message' => 'network radioring not found'], 404),
        '*/containers/radioring-*/json' => Http::response(['message' => 'No such container'], 404),
    ]);

    expect(app(DockerService::class)->startStationContainer($this->station))->toBeFalse();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'network radioring not found'))
        ->once();
});

// -- Pull-Stream ------------------------------------------------------------

test('a pull error inside a 200 response is detected', function () {
    Log::spy();

    Http::fake([
        '*/images/create*' => Http::response(
            '{"status":"Pulling from acme"}'."\n".'{"errorDetail":{"message":"manifest unknown"},"error":"manifest unknown"}',
            200,
        ),
        '*/containers/create*' => Http::response(['Id' => 'abc123'], 201),
        '*/containers/abc123/start' => Http::response('', 204),
    ]);

    // Best effort: ein lokal liegendes Image darf trotzdem starten.
    expect(app(DockerService::class)->startStationContainer($this->station))->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'manifest unknown'))
        ->once();
});

test('a failed pull is reported when the container cannot be created either', function () {
    Log::spy();

    Http::fake([
        '*/images/create*' => Http::response('{"error":"manifest unknown"}', 200),
        '*/containers/create*' => Http::response(['message' => 'No such image'], 404),
        '*/containers/radioring-*/json' => Http::response('', 404),
    ]);

    expect(app(DockerService::class)->startStationContainer($this->station))->toBeFalse();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'Image-Pull war zuvor fehlgeschlagen'))
        ->once();
});

test('registry auth is base64url encoded', function () {
    config([
        'radioring.registry_username' => 'acme',
        'radioring.registry_password' => 'secret',
    ]);
    fakeSuccessfulDocker();

    app(DockerService::class)->startStationContainer($this->station);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/images/create')) {
            return false;
        }

        $header = $request->header('X-Registry-Auth')[0] ?? '';

        // base64url: kein Plus, kein Schraegstrich, kein Padding.
        expect($header)->not->toContain('+')->not->toContain('/')->not->toContain('=');

        $decoded = json_decode(base64_decode(strtr($header, '-_', '+/')), true);

        return $decoded['username'] === 'acme' && $decoded['serveraddress'] === 'ghcr.io';
    });
});

// -- Stop / Restart / State -------------------------------------------------

test('stop removes the container', function () {
    Http::fake(['*/containers/radioring-*' => Http::response('', 204)]);

    expect(app(DockerService::class)->stopStationContainer($this->station))->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'force=true'));
});

test('stopping an already removed container succeeds', function () {
    Http::fake(['*/containers/radioring-*' => Http::response(['message' => 'No such container'], 404)]);

    expect(app(DockerService::class)->stopStationContainer($this->station))->toBeTrue();
});

test('restart tolerates a 304', function () {
    Http::fake(['*/containers/radioring-*/restart' => Http::response('', 304)]);

    expect(app(DockerService::class)->restartStationContainer($this->station))->toBeTrue();
});

test('container state is read from the inspect endpoint', function () {
    Http::fake(['*/containers/radioring-*/json' => Http::response(['State' => ['Status' => 'Running']], 200)]);

    expect(app(DockerService::class)->containerState($this->station))->toBe('running');
});

test('a missing container reports not_found', function () {
    Http::fake(['*/containers/radioring-*/json' => Http::response('', 404)]);

    expect(app(DockerService::class)->containerState($this->station))->toBe('not_found');
});

// -- Icecast-Sidecar --------------------------------------------------------

/**
 * Stations without an internal output must not trigger a single sidecar request, or every
 * laut.fm station pays a Docker roundtrip on each start and stop.
 */
function enableInternalIcecast(Station $station): void
{
    config([
        'radioring.stream.domain' => 'stream.example.com',
        'radioring.icecast.traefik_enabled' => true,
        'radioring.icecast.image' => 'ghcr.io/acme/icecast:latest',
        'radioring.icecast.web_network' => 'radioring-web',
        'radioring.icecast.cert_resolver' => 'radioring',
        'radioring.docker.station_network' => 'radioring',
    ]);

    $station->outputs()->create([
        'type' => 'internal',
        'host' => $station->icecastContainerName(),
        'port' => 8000,
        'mount' => '/stream',
        'username' => 'source',
        'bitrate' => 128,
        'enabled' => true,
    ]);
}

test('a station without an internal output never touches the sidecar', function () {
    fakeSuccessfulDocker();

    app(DockerService::class)->startStationContainer($this->station);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'radioring-icecast-'));
});

test('starting a station with an internal output brings up the sidecar', function () {
    enableInternalIcecast($this->station);
    fakeSuccessfulDocker();

    expect(app(DockerService::class)->startStationContainer($this->station->fresh()))->toBeTrue();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/containers/create?name=radioring-icecast-'.$this->station->slug)) {
            return false;
        }

        $data = $request->data();
        $labels = $data['Labels'] ?? [];
        $router = 'radioring-icecast-'.$this->station->slug;

        return $data['Image'] === 'ghcr.io/acme/icecast:latest'
            && ($labels['traefik.enable'] ?? null) === 'true'
            && ($labels["traefik.http.routers.{$router}.rule"] ?? null) === 'Host(`'.$this->station->slug.'.stream.example.com`)'
            && ($labels["traefik.http.services.{$router}.loadbalancer.server.port"] ?? null) === '8000'
            // Beide Netze: Liquidsoap erreicht ihn intern, Traefik sieht ihn im Web-Netz.
            && array_key_exists('radioring', $data['NetworkingConfig']['EndpointsConfig'])
            && array_key_exists('radioring-web', $data['NetworkingConfig']['EndpointsConfig']);
    });
});

test('the sidecar receives the source password of its station', function () {
    enableInternalIcecast($this->station);
    fakeSuccessfulDocker();

    app(DockerService::class)->startStationContainer($this->station->fresh());

    $password = $this->station->ensureStream()->icecast_password;

    Http::assertSent(function ($request) use ($password) {
        if (! str_contains($request->url(), 'name=radioring-icecast-')) {
            return false;
        }

        return in_array('ICECAST_SOURCE_PASSWORD='.$password, $request->data()['Env'] ?? [], true);
    });
});

test('a slug that could rewrite traefik rules gets no sidecar', function () {
    enableInternalIcecast($this->station);
    $this->station->update(['slug' => 'evil`) || Host(`panel.example.com']);
    fakeSuccessfulDocker();

    app(DockerService::class)->startStationContainer($this->station->fresh());

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'radioring-icecast-'));
});

test('stopping a station removes its sidecar as well', function () {
    enableInternalIcecast($this->station);
    Http::fake(['*' => Http::response('', 204)]);

    app(DockerService::class)->stopStationContainer($this->station->fresh());

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/containers/radioring-icecast-'.$this->station->slug.'?force=true'));
});

test('restart recreates the sidecar so a changed mount takes effect', function () {
    enableInternalIcecast($this->station);
    Http::fake([
        '*/images/create*' => Http::response('{"status":"Downloaded"}', 200),
        '*/containers/create*' => Http::response(['Id' => 'ice123'], 201),
        '*' => Http::response('', 204),
    ]);

    expect(app(DockerService::class)->restartStationContainer($this->station->fresh()))->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'radioring-icecast-'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'name=radioring-icecast-'));
});
