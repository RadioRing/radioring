<?php

use App\Livewire\MediaLibrary\FileModal;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

test('a window is stored with its weekdays and times', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->call('addAirtimeWindow')
        ->set('airtimeWindows.0.days', ['1', '2', '3', '4', '5'])
        ->set('airtimeWindows.0.from', '06:00')
        ->set('airtimeWindows.0.to', '10:00')
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh()->airtime_windows)->toBe([
        ['days' => [1, 2, 3, 4, 5], 'from' => '06:00', 'to' => '10:00'],
    ]);
});

test('a window covering all seven days is stored without weekdays', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->call('addAirtimeWindow')
        ->set('airtimeWindows.0.from', '20:00')
        ->set('airtimeWindows.0.to', '23:00')
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh()->airtime_windows)->toBe([
        ['days' => [], 'from' => '20:00', 'to' => '23:00'],
    ]);
});

test('removing the last window makes the file ungated again', function () {
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'airtime_windows' => [['days' => [1], 'from' => '06:00', 'to' => '10:00']],
    ]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->assertCount('airtimeWindows', 1)
        ->call('removeAirtimeWindow', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh()->airtime_windows)->toBeNull();
});

test('a malformed time is rejected', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->call('addAirtimeWindow')
        ->set('airtimeWindows.0.from', '25:00')
        ->call('save')
        ->assertHasErrors('airtimeWindows.0.from');

    expect($file->fresh()->airtime_windows)->toBeNull();
});
