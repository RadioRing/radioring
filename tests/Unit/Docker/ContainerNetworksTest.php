<?php

use App\Services\Docker\ContainerNetworks;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'radioring.docker.station_network' => 'radioring',
        'radioring.docker.stream_network' => 'radioring-stream',
        'radioring.icecast.web_network' => 'radioring-web',
    ]);
});

test('the icecast sidecar never joins the internal network', function () {
    expect(ContainerNetworks::forIcecast())
        ->toBe(['radioring-stream', 'radioring-web'])
        ->not->toContain('radioring');
});

test('the station container joins the internal and the stream network', function () {
    expect(ContainerNetworks::forStation())->toBe(['radioring', 'radioring-stream']);
});

test('empty and identical names collapse', function () {
    config([
        'radioring.docker.station_network' => 'web',
        'radioring.docker.stream_network' => 'web',
        'radioring.icecast.web_network' => '',
    ]);

    expect(ContainerNetworks::forStation())->toBe(['web']);
    expect(ContainerNetworks::forIcecast())->toBe(['web']);
});

test('no networks leave the payload on the default bridge', function () {
    expect(ContainerNetworks::payload([]))->toBe([]);
});

test('every network becomes an endpoint of the create payload', function () {
    $payload = ContainerNetworks::payload(['radioring', 'radioring-stream']);

    expect(array_keys($payload['NetworkingConfig']['EndpointsConfig']))->toBe(['radioring', 'radioring-stream']);
});
