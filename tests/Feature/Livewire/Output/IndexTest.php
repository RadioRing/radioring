<?php

use App\Livewire\Output\Index;
use App\Models\Station;
use App\Models\StationOutput;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

test('output manager renders', function () {
    Livewire::test(Index::class)->assertOk();
});

test('user can add an output with credentials', function () {
    Livewire::test(Index::class)
        ->call('createNew')
        ->set('type', 'lautfm')
        ->set('host', 'stream.laut.fm')
        ->set('port', 8080)
        ->set('mount', '/meinsender')
        ->set('username', 'source')
        ->set('password', 'geheim')
        ->set('bitrate', 192)
        ->call('save')
        ->assertHasNoErrors();

    $output = StationOutput::where('station_id', $this->station->id)->first();
    expect($output)->not->toBeNull();
    expect($output->type)->toBe('lautfm');
    expect($output->host)->toBe('stream.laut.fm');
    expect($output->port)->toBe(8080);
    expect($output->mount)->toBe('/meinsender');
    expect($output->bitrate)->toBe(192);
});

test('output password is stored encrypted', function () {
    Livewire::test(Index::class)
        ->call('createNew')
        ->set('host', 'stream.laut.fm')
        ->set('mount', '/x')
        ->set('password', 'supersecret')
        ->call('save')
        ->assertHasNoErrors();

    $output = StationOutput::where('station_id', $this->station->id)->first();
    expect($output->getRawOriginal('password_enc'))->not->toContain('supersecret');
    expect($output->password)->toBe('supersecret');
});

test('save requires host mount and password when creating', function () {
    Livewire::test(Index::class)
        ->call('createNew')
        ->set('host', '')
        ->set('mount', '')
        ->set('password', '')
        ->call('save')
        ->assertHasErrors(['host', 'mount', 'password']);
});

test('editing keeps existing password when left blank', function () {
    $output = $this->station->outputs()->create([
        'type' => 'lautfm',
        'host' => 'old.host',
        'port' => 8000,
        'mount' => '/old',
        'username' => 'source',
        'password' => 'original-pw',
        'bitrate' => 128,
        'enabled' => true,
    ]);

    Livewire::test(Index::class)
        ->call('edit', $output->id)
        ->set('host', 'new.host')
        ->set('password', '') // leer = unverändert
        ->call('save')
        ->assertHasNoErrors();

    $output->refresh();
    expect($output->host)->toBe('new.host');
    expect($output->password)->toBe('original-pw');
});

test('user can toggle and delete an output', function () {
    $output = $this->station->outputs()->create([
        'type' => 'icecast',
        'host' => 'h',
        'port' => 8000,
        'mount' => '/m',
        'username' => 'source',
        'password' => 'p',
        'bitrate' => 128,
        'enabled' => true,
    ]);

    Livewire::test(Index::class)->call('toggle', $output->id);
    expect($output->fresh()->enabled)->toBeFalse();

    Livewire::test(Index::class)->call('delete', $output->id);
    expect(StationOutput::find($output->id))->toBeNull();
});

test('user cannot edit an output of another station', function () {
    $otherStation = Station::factory()->create();
    $foreignOutput = $otherStation->outputs()->create([
        'type' => 'icecast',
        'host' => 'h',
        'port' => 8000,
        'mount' => '/m',
        'username' => 'source',
        'password' => 'p',
        'bitrate' => 128,
        'enabled' => true,
    ]);

    expect(fn () => Livewire::test(Index::class)->call('edit', $foreignOutput->id))
        ->toThrow(ModelNotFoundException::class);
});
