<?php

use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Stations;
use App\Livewire\Station\Edit;
use App\Models\Station;
use App\Models\User;
use App\Support\StereoToolTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('the licence starts out unaccepted', function () {
    expect(StereoToolTerms::accepted())->toBeFalse();
    expect(StereoToolTerms::acceptedAt())->toBeNull();
    expect(StereoToolTerms::acceptedBy())->toBeNull();
});

test('an admin accepts the licence and the acceptance is recorded', function () {
    Livewire::test(Settings::class)
        ->set('stereoToolTermsAgreed', true)
        ->call('acceptStereoToolTerms')
        ->assertHasNoErrors();

    expect(StereoToolTerms::accepted())->toBeTrue();
    expect(StereoToolTerms::acceptedAt())->not->toBeNull();
    expect(StereoToolTerms::acceptedBy()?->id)->toBe($this->admin->id);
});

test('accepting without ticking the box is rejected', function () {
    Livewire::test(Settings::class)
        ->set('stereoToolTermsAgreed', false)
        ->call('acceptStereoToolTerms')
        ->assertHasErrors('stereoToolTermsAgreed');

    expect(StereoToolTerms::accepted())->toBeFalse();
});

test('withdrawing acceptance disables stereo tool on every station but keeps the configuration', function () {
    StereoToolTerms::accept($this->admin);

    $station = Station::factory()->create([
        'stereo_tool_enabled' => true,
        'stereo_tool_license_key' => 'the-license',
        'stereo_tool_preset' => 'pop',
    ]);

    Livewire::test(Settings::class)->call('revokeStereoToolTerms');

    expect(StereoToolTerms::accepted())->toBeFalse();

    $station->refresh();
    expect($station->stereo_tool_enabled)->toBeFalse();
    expect($station->stereoToolActive())->toBeFalse();

    // Keys and presets survive, so re-accepting only needs the stations enabled again.
    expect($station->stereo_tool_license_key)->toBe('the-license');
    expect($station->stereo_tool_preset)->toBe('pop');
});

test('stereo tool cannot be enabled for a station while the licence is unaccepted', function () {
    $station = Station::factory()->create(['stereo_tool_enabled' => false]);

    Livewire::test(Stations::class)->call('toggleStereoTool', $station->id);

    expect($station->fresh()->stereo_tool_enabled)->toBeFalse();
});

test('stereo tool can still be disabled after the licence was withdrawn', function () {
    $station = Station::factory()->create(['stereo_tool_enabled' => true]);

    expect(StereoToolTerms::accepted())->toBeFalse();

    Livewire::test(Stations::class)->call('toggleStereoTool', $station->id);

    expect($station->fresh()->stereo_tool_enabled)->toBeFalse();
});

test('the station list points to the settings page while the licence is unaccepted', function () {
    Station::factory()->create(['name' => 'Alpha Radio', 'stereo_tool_enabled' => false]);

    Livewire::test(Stations::class)->assertSee(__('Licence not accepted'));

    StereoToolTerms::accept($this->admin);

    Livewire::test(Stations::class)
        ->assertDontSee(__('Licence not accepted'))
        ->assertSee(__('Enable'));
});

test('the station settings name the licence the processing falls under', function () {
    $owner = User::factory()->create();
    $station = Station::factory()->create([
        'user_id' => $owner->id,
        'stereo_tool_enabled' => true,
    ]);

    $this->actingAs($owner);

    Livewire::test(Edit::class, ['station' => $station])
        ->assertSee(__('Read the licence'))
        ->assertSee(config('radioring.stereo_tool.licence_url'));
});
