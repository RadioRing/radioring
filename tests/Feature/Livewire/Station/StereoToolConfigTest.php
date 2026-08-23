<?php

use App\Livewire\Station\Edit;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
});

test('the owner can set license key and preset once stereo tool is enabled', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
    ]);

    Livewire::test(Edit::class, ['station' => $station])
        ->set('stereoToolLicenseKey', 'the-license')
        ->set('stereoToolPreset', 'pop')
        ->call('save')
        ->assertHasNoErrors();

    $station->refresh();
    expect($station->stereo_tool_license_key)->toBe('the-license');
    expect($station->stereo_tool_preset)->toBe('pop');
    expect($station->stereoToolActive())->toBeTrue();
});

test('an invalid preset is rejected', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => true,
    ]);

    Livewire::test(Edit::class, ['station' => $station])
        ->set('stereoToolPreset', 'does-not-exist')
        ->call('save')
        ->assertHasErrors('stereoToolPreset');
});

test('stereo tool config is ignored when the station is not enabled', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => false,
    ]);

    Livewire::test(Edit::class, ['station' => $station])
        ->set('stereoToolLicenseKey', 'sneaky')
        ->set('stereoToolPreset', 'pop')
        ->call('save')
        ->assertHasNoErrors();

    $station->refresh();
    expect($station->stereo_tool_license_key)->toBeNull();
    expect($station->stereo_tool_preset)->toBeNull();
});

test('the owner cannot enable stereo tool through the edit form', function () {
    $station = Station::factory()->create([
        'user_id' => $this->owner->id,
        'stereo_tool_enabled' => false,
    ]);

    // stereo_tool_enabled ist nicht fillable und wird vom Edit-Component nie gesetzt –
    // die Freischaltung bleibt dem Admin vorbehalten.
    Livewire::test(Edit::class, ['station' => $station])
        ->set('name', 'Neuer Name')
        ->call('save')
        ->assertHasNoErrors();

    expect($station->fresh()->stereo_tool_enabled)->toBeFalse();
});
