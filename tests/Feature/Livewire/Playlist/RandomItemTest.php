<?php

use App\Livewire\Playlist\Manager;
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
    $this->playlist = $this->station->playlists()->create([
        'name' => 'Test Playlist',
        'playback_mode' => 'sequential',
    ]);
    $this->actingAs($this->user);
});

test('user can add a random item without tags', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'special:random');

    $item = $this->playlist->items()->first();
    expect($item->type)->toBe('random')
        ->and($item->title)->toBe('Zufälliges Element')
        ->and($item->fill_tags)->toBeNull()
        ->and($item->media_file_id)->toBeNull();
});

test('user can add a random item with tag filter', function () {
    $tag = $this->station->tags()->create(['name' => 'Jingles']);

    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'special:random');

    $item = $this->playlist->items()->first();

    $component->call('startEditingItem', $item->id)
        ->set('editFillTagIds', [(string) $tag->id])
        ->call('saveItem');

    expect($item->fresh()->fill_tags)->toContain($tag->id);
});

test('foreign tag ids are rejected in random item', function () {
    $otherStation = Station::factory()->create();
    $foreignTag = $otherStation->tags()->create(['name' => 'Fremd']);

    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'special:random');

    $item = $this->playlist->items()->first();

    $component->call('startEditingItem', $item->id)
        ->set('editFillTagIds', [(string) $foreignTag->id])
        ->call('saveItem');

    expect($item->fresh()->fill_tags)->toBeEmpty();
});

test('rundown generator picks exactly one tagged media file for a random item', function () {
    $tag = $this->station->tags()->create(['name' => 'Jingles']);

    $jingle = $this->station->mediaFiles()->create([
        'title' => 'Jingle A',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/jingle-a.mp3',
        'duration_seconds' => 8,
    ]);
    $jingle->tags()->attach($tag->id);

    // Ein nicht getaggtes Medium darf nie gewählt werden.
    $this->station->mediaFiles()->create([
        'title' => 'Untagged Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
        'duration_seconds' => 200,
    ]);

    $this->playlist->items()->create([
        'position' => 0,
        'type' => 'random',
        'title' => 'Zufälliges Element',
        'fill_tags' => [$tag->id],
    ]);

    $slot = HourGridSlot::factory()->create([
        'station_id' => $this->station->id,
        'playlist_id' => $this->playlist->id,
        'weekday' => 0,
        'hour' => 10,
    ]);

    $rundown = app(RundownGeneratorService::class)
        ->generate($this->station, $slot, Carbon::parse('2026-05-04'), true);

    expect($rundown->items)->toHaveCount(1);

    $item = $rundown->items->first();
    expect($item->source_type)->toBe('resolved_random')
        ->and($item->media_file_id)->toBe($jingle->id)
        ->and($item->duration_seconds)->toBe(8);
});

test('random item is skipped when no matching media file exists', function () {
    $tag = $this->station->tags()->create(['name' => 'Leer']);

    $this->playlist->items()->create([
        'position' => 0,
        'type' => 'random',
        'title' => 'Zufälliges Element',
        'fill_tags' => [$tag->id],
    ]);

    $slot = HourGridSlot::factory()->create([
        'station_id' => $this->station->id,
        'playlist_id' => $this->playlist->id,
        'weekday' => 0,
        'hour' => 10,
    ]);

    $rundown = app(RundownGeneratorService::class)
        ->generate($this->station, $slot, Carbon::parse('2026-05-04'), true);

    expect($rundown->items)->toHaveCount(0);
});
