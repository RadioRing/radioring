<?php

use App\Livewire\Station\Create;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the create page offers stations the user was invited to', function () {
    $station = Station::factory()->create(['name' => 'Shared Radio']);
    $editor = User::factory()->create();
    $station->members()->attach($editor->id, ['role' => 'editor']);

    Livewire::actingAs($editor)
        ->test(Create::class)
        ->assertSee('Switch to an existing station')
        ->assertSee('Shared Radio')
        ->assertSee(route('station.select'));
});

test('an invited user can switch to the shared station from the create page', function () {
    $station = Station::factory()->create();
    $owner = User::factory()->create();
    $station->members()->attach($owner->id, ['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(Create::class)
        ->call('choose', $station->id)
        ->assertRedirect(route('dashboard'));

    expect($owner->currentStation()?->id)->toBe($station->id);
});

test('a user cannot switch to a station without access from the create page', function () {
    $station = Station::factory()->create();

    expect(fn () => Livewire::actingAs(User::factory()->create())
        ->test(Create::class)
        ->call('choose', $station->id))
        ->toThrow(ModelNotFoundException::class);
});

test('a user without any station sees no switch option', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(Create::class)
        ->assertDontSee('Switch to an existing station');
});
