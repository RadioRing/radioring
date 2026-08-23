<?php

use App\Livewire\Station\Edit;
use App\Livewire\Station\Select;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->owner->id]);
});

test('creating a station gives the owner an owner pivot row', function () {
    expect($this->station->roleFor($this->owner))->toBe('owner');
    expect($this->owner->accessibleStations()->pluck('stations.id'))->toContain($this->station->id);
});

test('owner can toggle nightly rundown regeneration', function () {
    expect($this->station->regenerate_rundowns_nightly)->toBeFalse();

    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->assertSet('regenerateRundownsNightly', false)
        ->set('regenerateRundownsNightly', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->station->fresh()->regenerate_rundowns_nightly)->toBeTrue();
});

test('owner can grant a registered user access by email', function () {
    $colleague = User::factory()->create();

    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->set('memberEmail', $colleague->email)
        ->call('addMember')
        ->assertHasNoErrors();

    expect($this->station->fresh()->roleFor($colleague))->toBe('editor');
    expect($colleague->accessibleStations()->pluck('stations.id'))->toContain($this->station->id);
});

test('adding an unknown email shows an error', function () {
    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->set('memberEmail', 'ghost@example.com')
        ->call('addMember')
        ->assertHasErrors('memberEmail');
});

test('a user cannot be added twice', function () {
    $colleague = User::factory()->create();
    $this->station->members()->attach($colleague->id, ['role' => 'editor']);

    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->set('memberEmail', $colleague->email)
        ->call('addMember')
        ->assertHasErrors('memberEmail');
});

test('owner can revoke access', function () {
    $colleague = User::factory()->create();
    $this->station->members()->attach($colleague->id, ['role' => 'editor']);

    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->call('removeMember', $colleague->id)
        ->assertHasNoErrors();

    expect($this->station->fresh()->roleFor($colleague))->toBeNull();
});

test('the owner cannot be removed', function () {
    Livewire::actingAs($this->owner)
        ->test(Edit::class, ['station' => $this->station])
        ->call('removeMember', $this->owner->id);

    expect($this->station->fresh()->roleFor($this->owner))->toBe('owner');
});

test('a non-owner editor cannot open the station management screen', function () {
    $colleague = User::factory()->create();
    $this->station->members()->attach($colleague->id, ['role' => 'editor']);

    Livewire::actingAs($colleague)
        ->test(Edit::class, ['station' => $this->station])
        ->assertForbidden();
});

test('a shared editor can select the station and reach the dashboard', function () {
    $colleague = User::factory()->create();
    $this->station->members()->attach($colleague->id, ['role' => 'editor']);

    Livewire::actingAs($colleague)
        ->test(Select::class)
        ->call('choose', $this->station->id)
        ->assertHasNoErrors();

    expect($colleague->currentStation()?->id)->toBe($this->station->id);
});
