<?php

use App\Livewire\Playlist\Manager;
use App\Models\ExternalSource;
use App\Models\HourGridSlot;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->playlist = $this->station->playlists()->create(['name' => 'P', 'playback_mode' => 'sequential']);
    $this->actingAs($this->user);
});

test('user can add an external source to a playlist', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'name' => 'Morgenshow', 'expected_duration_seconds' => 1800,
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'external')
        ->set('newExternalSourceId', $source->id)
        ->call('addItem')
        ->assertHasNoErrors();

    $item = $this->playlist->items()->first();
    expect($item->type)->toBe('external')
        ->and($item->external_source_id)->toBe($source->id)
        ->and($item->title)->toBe('Morgenshow')
        // Kein Dauer-Snapshot mehr – die Länge kommt dynamisch aus der Quelle.
        ->and($item->duration_seconds)->toBeNull();
});

test('regenerating uses the current source duration, not a stale item snapshot', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'expected_duration_seconds' => 1200,
    ]);
    // Altes Item mit eingefrorenem 60-Minuten-Snapshot (wie vor dem Fix angelegt).
    $this->playlist->items()->create([
        'position' => 0, 'type' => 'external', 'title' => $source->name,
        'external_source_id' => $source->id, 'duration_seconds' => 3600,
    ]);

    $slot = HourGridSlot::factory()->create([
        'station_id' => $this->station->id, 'playlist_id' => $this->playlist->id, 'weekday' => 0, 'hour' => 10,
    ]);

    $rundown = app(RundownGeneratorService::class)
        ->generate($this->station, $slot, Carbon::parse('2026-05-04'), true);

    // Aktuelle Quellen-Dauer (1200) gewinnt gegen den veralteten Item-Snapshot (3600).
    expect($rundown->items()->first()->duration_seconds)->toBe(1200);
});

test('adding an external item requires a source', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'external')
        ->set('newExternalSourceId', null)
        ->call('addItem')
        ->assertHasErrors(['newExternalSourceId']);
});

test('the rundown generator carries the external source reference through', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'expected_duration_seconds' => 240,
    ]);
    $this->playlist->items()->create([
        'position' => 0, 'type' => 'external', 'title' => $source->name, 'external_source_id' => $source->id,
    ]);

    $slot = HourGridSlot::factory()->create([
        'station_id' => $this->station->id, 'playlist_id' => $this->playlist->id, 'weekday' => 0, 'hour' => 10,
    ]);

    $rundown = app(RundownGeneratorService::class)
        ->generate($this->station, $slot, Carbon::parse('2026-05-04'), true);

    $item = $rundown->items()->first();
    expect($item->source_type)->toBe('external')
        ->and($item->external_source_id)->toBe($source->id)
        ->and($item->media_file_id)->toBeNull()
        ->and($item->duration_seconds)->toBe(240);
});
