<?php

use App\Contracts\ContainerServiceInterface;
use App\Jobs\StartStationContainer;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create(['user_id' => User::factory()]);
});

test('job starts container and marks stream running', function () {
    $this->mock(ContainerServiceInterface::class)
        ->shouldReceive('startStationContainer')
        ->once()
        ->andReturnTrue();

    (new StartStationContainer($this->station->id))->handle(app(ContainerServiceInterface::class));

    $stream = $this->station->stream()->first();
    expect($stream)->not->toBeNull();
    expect($stream->status)->toBe('running');
    expect($stream->last_started_at)->not->toBeNull();
});

test('job marks stream as error and throws when start fails', function () {
    $this->mock(ContainerServiceInterface::class)
        ->shouldReceive('startStationContainer')
        ->once()
        ->andReturnFalse();

    expect(fn () => (new StartStationContainer($this->station->id))->handle(app(ContainerServiceInterface::class)))
        ->toThrow(RuntimeException::class);

    expect($this->station->stream()->first()->status)->toBe('error');
});
