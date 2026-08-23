<?php

use App\Contracts\ContainerServiceInterface;
use App\Jobs\StartStationContainer;
use App\Livewire\Dashboard;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'radioring.container_driver' => 'docker',
        'radioring.docker.host' => 'tcp://dockerproxy:2375',
    ]);

    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->user->setCurrentStation($this->station);
    $this->actingAs($this->user);
});

test('start dispatches job and sets stream to starting', function () {
    Queue::fake();

    Livewire::test(Dashboard::class)->call('startStation');

    Queue::assertPushed(StartStationContainer::class,
        fn ($job) => $job->stationId === $this->station->id);

    expect($this->station->stream()->first()->status)->toBe('starting');
});

test('stop calls the container service and sets stream stopped', function () {
    $this->station->stream()->create([
        'container_name' => 'radioring-'.$this->station->slug,
        'status' => 'running',
    ]);

    $this->mock(ContainerServiceInterface::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturnTrue();
        $mock->shouldReceive('stopStationContainer')->once()->andReturnTrue();
    });

    Livewire::test(Dashboard::class)->call('stopStation');

    expect($this->station->stream()->first()->status)->toBe('stopped');
});

test('restart calls the container service', function () {
    $this->station->stream()->create([
        'container_name' => 'radioring-'.$this->station->slug,
        'status' => 'running',
    ]);

    $this->mock(ContainerServiceInterface::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturnTrue();
        $mock->shouldReceive('restartStationContainer')->once()->andReturnTrue();
    });

    Livewire::test(Dashboard::class)->call('restartStation');

    expect($this->station->stream()->first()->status)->toBe('running');
});

test('actions warn instead of acting when the container driver is not configured', function () {
    // Leerer DOCKER_HOST: der Treiber meldet sich als nicht einsatzbereit.
    config(['radioring.docker.host' => '']);
    Queue::fake();

    Livewire::test(Dashboard::class)->call('startStation');

    Queue::assertNothingPushed();
    expect($this->station->stream()->first())->toBeNull();
});

test('the legacy portainer driver is honoured when selected', function () {
    config([
        'radioring.container_driver' => 'portainer',
        'services.portainer.endpoint' => '',
        'services.portainer.token' => '',
    ]);
    Queue::fake();

    Livewire::test(Dashboard::class)->call('startStation');

    Queue::assertNothingPushed();
});
