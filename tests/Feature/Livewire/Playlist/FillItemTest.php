<?php

use App\Livewire\Playlist\Manager;
use App\Models\Station;
use App\Models\User;
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

test('user can add a fill item without tags', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'special:fill');

    $item = $this->playlist->items()->first();
    expect($item->type)->toBe('fill')
        ->and($item->fill_tags)->toBeNull()
        ->and($item->fill_max_duration_seconds)->toBeNull();
});

test('user can set a tag filter on a fill item', function () {
    $tag1 = $this->station->tags()->create(['name' => '80er']);
    $tag2 = $this->station->tags()->create(['name' => '90er']);

    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'special:fill');

    $item = $this->playlist->items()->first();

    $component->call('startEditingItem', $item->id)
        ->set('editFillTagIds', [(string) $tag1->id, (string) $tag2->id])
        ->call('saveItem');

    expect($item->fresh()->fill_tags)->toContain($tag1->id)
        ->and($item->fresh()->fill_tags)->toContain($tag2->id);
});

test('user can set a max duration on a fill item', function () {
    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'special:fill');

    $item = $this->playlist->items()->first();

    $component->call('startEditingItem', $item->id)
        ->set('editFillMaxDuration', '1800')
        ->call('saveItem');

    expect($item->fresh()->fill_max_duration_seconds)->toBe(1800);
});

test('fill max duration must be at least 60 seconds', function () {
    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'special:fill');

    $item = $this->playlist->items()->first();

    $component->call('startEditingItem', $item->id)
        ->set('editFillMaxDuration', '30')
        ->call('saveItem')
        ->assertHasErrors(['editFillMaxDuration']);
});

test('foreign tag ids are rejected in fill item', function () {
    $otherStation = Station::factory()->create();
    $foreignTag = $otherStation->tags()->create(['name' => 'Fremd']);

    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'special:fill');

    $item = $this->playlist->items()->first();

    $component->call('startEditingItem', $item->id)
        ->set('editFillTagIds', [(string) $foreignTag->id])
        ->call('saveItem');

    expect($item->fresh()->fill_tags)->toBeEmpty();
});

test('user can set a relative offset in mm:ss format', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Stunden-Jingle',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/jingle.mp3',
    ]);

    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'media:'.$file->id);

    $item = $this->playlist->items()->first();

    $component->call('startEditingItem', $item->id)
        ->set('editRelativeOffset', '15:00')
        ->call('saveItem');

    expect($item->fresh()->relative_offset_seconds)->toBe(900);
});

test('relative offset in pure seconds is also accepted', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Jingle',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/jingle.mp3',
    ]);

    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'media:'.$file->id);

    $item = $this->playlist->items()->first();

    $component->call('startEditingItem', $item->id)
        ->set('editRelativeOffset', '300')
        ->call('saveItem');

    expect($item->fresh()->relative_offset_seconds)->toBe(300);
});

test('an element from the palette starts without a timestamp', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'media:'.$file->id);

    $item = $this->playlist->items()->first();

    expect($item->relative_offset_seconds)->toBeNull()
        ->and($item->type)->toBe('music')
        ->and($item->media_file_id)->toBe($file->id);
});
