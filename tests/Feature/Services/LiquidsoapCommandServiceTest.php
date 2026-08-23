<?php

use App\Models\Station;
use App\Models\User;
use App\Services\LiquidsoapCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['radioring.control_channel' => 'radioring_station_control']);
    $this->station = Station::factory()->create(['user_id' => User::factory()]);
});

test('skip publishes a skip command for the station container', function () {
    $connection = Mockery::mock();
    $connection->shouldReceive('client')->andReturn(new stdClass);
    $connection->shouldReceive('publish')->once()->withArgs(function ($channel, $payload) {
        $data = json_decode($payload, true);

        return $channel === 'radioring_station_control'
            && $data['command'] === 'skip'
            && $data['container_name'] === 'radioring-'.$this->station->slug;
    })->andReturn(1);

    Redis::shouldReceive('connection')->once()->andReturn($connection);

    expect(app(LiquidsoapCommandService::class)->skip($this->station))->toBeTrue();
});

test('uses the station stream container name when present', function () {
    $this->station->stream()->create(['container_name' => 'custom-name', 'status' => 'running']);

    $connection = Mockery::mock();
    $connection->shouldReceive('client')->andReturn(new stdClass);
    $connection->shouldReceive('publish')->once()->withArgs(function ($channel, $payload) {
        return json_decode($payload, true)['container_name'] === 'custom-name';
    })->andReturn(1);

    Redis::shouldReceive('connection')->once()->andReturn($connection);

    app(LiquidsoapCommandService::class)->restart($this->station->fresh());
});

test('publishes on the raw channel without the laravel key prefix', function () {
    // phpredis hängt OPT_PREFIX auch an pub/sub-Channels – der Container lauscht aber
    // roh. Der Service muss den Prefix beim Publish temporär entfernen und danach
    // wiederherstellen.
    $client = Mockery::mock(\Redis::class);
    $client->shouldReceive('getOption')->with(\Redis::OPT_PREFIX)->andReturn('laravel-database-');
    $client->shouldReceive('setOption')->with(\Redis::OPT_PREFIX, '')->once();
    $client->shouldReceive('publish')->once()->with('radioring_station_control', Mockery::type('string'))->andReturn(2);
    $client->shouldReceive('setOption')->with(\Redis::OPT_PREFIX, 'laravel-database-')->once();

    $connection = Mockery::mock();
    $connection->shouldReceive('client')->andReturn($client);

    Redis::shouldReceive('connection')->once()->andReturn($connection);

    expect(app(LiquidsoapCommandService::class)->skip($this->station))->toBeTrue();
});

test('returns false when redis throws', function () {
    Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('no redis'));

    expect(app(LiquidsoapCommandService::class)->skip($this->station))->toBeFalse();
});
