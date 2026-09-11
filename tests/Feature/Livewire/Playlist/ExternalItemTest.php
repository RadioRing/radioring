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
        ->call('insertEntry', 'external:'.$source->id)
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

test('inserting without a pick adds nothing', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertPicks')
        ->assertHasNoErrors();

    expect($this->playlist->items()->count())->toBe(0);
});

test('a source of another station cannot be inserted', function () {
    $foreign = ExternalSource::factory()->create(['station_id' => Station::factory()->create()->id]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'external:'.$foreign->id);

    expect($this->playlist->items()->count())->toBe(0);
});

test('several external sources can be added at once, in the order they were picked', function () {
    $first = ExternalSource::factory()->create(['station_id' => $this->station->id, 'name' => 'Wetter']);
    $second = ExternalSource::factory()->create(['station_id' => $this->station->id, 'name' => 'Nachrichten']);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('togglePick', 'external:'.$second->id)
        ->call('togglePick', 'external:'.$first->id)
        ->call('insertPicks')
        ->assertHasNoErrors()
        ->assertSet('picks', []);

    $items = $this->playlist->items()->orderBy('position')->get();
    expect($items->pluck('external_source_id')->all())->toBe([$second->id, $first->id]);
});

test('a picked external source can be dropped again before adding', function () {
    $source = ExternalSource::factory()->create(['station_id' => $this->station->id]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('togglePick', 'external:'.$source->id)
        ->call('togglePick', 'external:'.$source->id)
        ->assertSet('picks', [])
        ->call('insertPicks');

    expect($this->playlist->items()->count())->toBe(0);
});

test('the source list can be searched by name', function () {
    ExternalSource::factory()->create(['station_id' => $this->station->id, 'name' => 'Morgenshow']);
    ExternalSource::factory()->create(['station_id' => $this->station->id, 'name' => 'Wetter']);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('switchTab', 'external')
        ->set('paletteSearch', 'wett')
        ->assertSee('Wetter')
        ->assertDontSee('Morgenshow');
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
