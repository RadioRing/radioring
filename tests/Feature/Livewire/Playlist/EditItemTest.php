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

test('user can update the relative offset of an item', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Jingle',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/jingle.mp3',
    ]);
    $item = $this->playlist->items()->create([
        'position' => 0,
        'type' => 'jingle',
        'title' => 'Jingle',
        'media_file_id' => $file->id,
        'relative_offset_seconds' => 300,
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('startEditingItem', $item->id)
        ->assertSet('editRelativeOffset', '05:00')
        ->set('editRelativeOffset', '15:00')
        ->call('saveItem');

    expect($item->fresh()->relative_offset_seconds)->toBe(900);
});

test('user can clear the relative offset of an item', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
    ]);
    $item = $this->playlist->items()->create([
        'position' => 0,
        'type' => 'music',
        'title' => 'Song',
        'media_file_id' => $file->id,
        'relative_offset_seconds' => 600,
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('startEditingItem', $item->id)
        ->set('editRelativeOffset', '')
        ->call('saveItem');

    expect($item->fresh()->relative_offset_seconds)->toBeNull();
});

test('user can update tags on a fill item', function () {
    $tag1 = $this->station->tags()->create(['name' => '80er']);
    $tag2 = $this->station->tags()->create(['name' => '90er']);
    $item = $this->playlist->items()->create([
        'position' => 0,
        'type' => 'fill',
        'title' => 'Auffüllen mit Musik',
        'fill_tags' => [$tag1->id],
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('startEditingItem', $item->id)
        ->assertSet('editFillTagIds', [(string) $tag1->id])
        ->set('editFillTagIds', [(string) $tag1->id, (string) $tag2->id])
        ->call('saveItem');

    expect($item->fresh()->fill_tags)->toContain($tag1->id)->toContain($tag2->id);
});

test('user can update max duration on a fill item', function () {
    $item = $this->playlist->items()->create([
        'position' => 0,
        'type' => 'fill',
        'title' => 'Auffüllen mit Musik',
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('startEditingItem', $item->id)
        ->set('editFillMaxDuration', '1800')
        ->call('saveItem');

    expect($item->fresh()->fill_max_duration_seconds)->toBe(1800);
});

test('fill max duration must be at least 60 seconds when editing', function () {
    $item = $this->playlist->items()->create([
        'position' => 0,
        'type' => 'fill',
        'title' => 'Auffüllen mit Musik',
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('startEditingItem', $item->id)
        ->set('editFillMaxDuration', '30')
        ->call('saveItem')
        ->assertHasErrors(['editFillMaxDuration']);
});

test('cancel editing clears editing state', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
    ]);
    $item = $this->playlist->items()->create([
        'position' => 0,
        'type' => 'music',
        'title' => 'Song',
        'media_file_id' => $file->id,
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('startEditingItem', $item->id)
        ->assertSet('editingItemId', $item->id)
        ->call('cancelEditingItem')
        ->assertSet('editingItemId', null);
});
