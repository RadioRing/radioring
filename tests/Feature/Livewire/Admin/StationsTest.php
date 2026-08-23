<?php

use App\Livewire\Admin\Stations;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('an admin can enable and disable stereo tool for a station', function () {
    $station = Station::factory()->create();

    expect($station->fresh()->stereo_tool_enabled)->toBeFalse();

    Livewire::test(Stations::class)->call('toggleStereoTool', $station->id);
    expect($station->fresh()->stereo_tool_enabled)->toBeTrue();

    Livewire::test(Stations::class)->call('toggleStereoTool', $station->id);
    expect($station->fresh()->stereo_tool_enabled)->toBeFalse();
});

test('the station list can be searched by name or slug', function () {
    Station::factory()->create(['name' => 'Alpha Radio', 'slug' => 'alpha-radio']);
    Station::factory()->create(['name' => 'Beta FM', 'slug' => 'beta-fm']);

    Livewire::test(Stations::class)
        ->set('search', 'alpha')
        ->assertSee('Alpha Radio')
        ->assertDontSee('Beta FM');
});

test('the admin stations page is gated by the admin middleware', function () {
    $this->get(route('admin.stations'))->assertOk();

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.stations'))->assertForbidden();
});
