<?php

use App\Contracts\ContainerServiceInterface;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create(['slug' => 'demo']);
});

// -- Verschluesselung -------------------------------------------------------

test('the api token is stored encrypted', function () {
    $stored = DB::table('stations')->where('id', $this->station->id)->value('api_token');

    expect($stored)->not->toBe($this->station->api_token)
        ->and(strlen($stored))->toBeGreaterThan(64)
        ->and($this->station->fresh()->api_token)->toBe($this->station->api_token);
});

test('two stations get different tokens', function () {
    $other = Station::factory()->create();

    expect($other->api_token)->not->toBe($this->station->api_token);
});

// -- Rotation ---------------------------------------------------------------

test('it rotates the token and recreates the container', function () {
    $old = $this->station->api_token;

    $this->mock(ContainerServiceInterface::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturnTrue();
        $mock->shouldReceive('stopStationContainer')->once()->andReturnTrue();
        $mock->shouldReceive('startStationContainer')->once()->andReturnTrue();
    });

    $this->artisan('station:rotate-token', ['station' => 'demo', '--force' => true])
        ->assertSuccessful();

    expect($this->station->fresh()->api_token)->not->toBe($old);
});

test('it asks before interrupting playback', function () {
    $old = $this->station->api_token;

    $this->artisan('station:rotate-token', ['station' => 'demo'])
        ->expectsConfirmation('Fortfahren?', 'no')
        ->assertSuccessful();

    expect($this->station->fresh()->api_token)->toBe($old);
});

test('--no-restart rotates without touching the container', function () {
    $old = $this->station->api_token;

    // Wird der Container doch angefasst, schlaegt der Mock fehl.
    $this->mock(ContainerServiceInterface::class, function ($mock) {
        $mock->shouldReceive('stopStationContainer')->never();
        $mock->shouldReceive('startStationContainer')->never();
    });

    $this->artisan('station:rotate-token', ['station' => 'demo', '--no-restart' => true])
        ->assertSuccessful();

    expect($this->station->fresh()->api_token)->not->toBe($old);
});

test('it accepts an id as well as a slug', function () {
    $this->mock(ContainerServiceInterface::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturnTrue();
        $mock->shouldReceive('stopStationContainer')->andReturnTrue();
        $mock->shouldReceive('startStationContainer')->andReturnTrue();
    });

    $this->artisan('station:rotate-token', ['station' => (string) $this->station->id, '--force' => true])
        ->assertSuccessful();
});

test('it reports an unknown station', function () {
    $this->artisan('station:rotate-token', ['station' => 'nope', '--force' => true])
        ->assertFailed();
});

test('it skips the restart when no container driver is configured', function () {
    $old = $this->station->api_token;

    $this->mock(ContainerServiceInterface::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturnFalse();
        $mock->shouldReceive('stopStationContainer')->never();
    });

    $this->artisan('station:rotate-token', ['station' => 'demo', '--force' => true])
        ->assertSuccessful();

    expect($this->station->fresh()->api_token)->not->toBe($old);
});

test('the old token stops working after rotation', function () {
    $old = $this->station->api_token;

    $this->mock(ContainerServiceInterface::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturnTrue();
        $mock->shouldReceive('stopStationContainer')->andReturnTrue();
        $mock->shouldReceive('startStationContainer')->andReturnTrue();
    });

    $this->artisan('station:rotate-token', ['station' => 'demo', '--force' => true]);

    $this->withToken($old)
        ->get("/api/liquidsoap/{$this->station->slug}/script")
        ->assertUnauthorized();

    $this->withToken($this->station->fresh()->api_token)
        ->get("/api/liquidsoap/{$this->station->slug}/script")
        ->assertOk();
});
