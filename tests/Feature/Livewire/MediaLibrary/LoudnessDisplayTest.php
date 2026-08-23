<?php

use App\Livewire\MediaLibrary\Index;
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

test('library shows the measured loudness and applied gain', function () {
    config(['radioring.loudness.target_lufs' => -14.0]);

    MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'title' => 'Quiet Song',
        'loudness_lufs' => -20.0,
        'loudness_true_peak' => -8.0,
        'loudness_measured_at' => now(),
    ]);

    Livewire::test(Index::class)
        ->assertSee('-20.0 LUFS')
        ->assertSee('+6.0 dB');
});

test('library marks an unmeasured own file as pending', function () {
    MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'title' => 'Fresh Upload',
        'loudness_lufs' => null,
    ]);

    Livewire::test(Index::class)
        ->assertSee('Lautheit ausstehend');
});
