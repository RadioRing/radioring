<?php

use App\Livewire\Playlist\Manager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
use App\Models\Station;
use App\Models\User;
use Livewire\Livewire;

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
        ->set('newType', 'fill')
        ->call('addItem');

    $item = $this->playlist->items()->first();
    expect($item->type)->toBe('fill')
        ->and($item->fill_tags)->toBeNull()
        ->and($item->fill_max_duration_seconds)->toBeNull();
});

test('user can add a fill item with tag filter', function () {
    $tag1 = $this->station->tags()->create(['name' => '80er']);
    $tag2 = $this->station->tags()->create(['name' => '90er']);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'fill')
        ->set('newFillTagIds', [(string) $tag1->id, (string) $tag2->id])
        ->call('addItem');

    $item = $this->playlist->items()->first();
    expect($item->type)->toBe('fill')
        ->and($item->fill_tags)->toContain($tag1->id)
        ->and($item->fill_tags)->toContain($tag2->id);
});

test('user can add a fill item with max duration', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'fill')
        ->set('newFillMaxDuration', '1800')
        ->call('addItem');

    expect($this->playlist->items()->first()->fill_max_duration_seconds)->toBe(1800);
});

test('fill max duration must be at least 60 seconds', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'fill')
        ->set('newFillMaxDuration', '30')
        ->call('addItem')
        ->assertHasErrors(['newFillMaxDuration']);
});

test('foreign tag ids are rejected in fill item', function () {
    $otherStation = Station::factory()->create();
    $foreignTag = $otherStation->tags()->create(['name' => 'Fremd']);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'fill')
        ->set('newFillTagIds', [(string) $foreignTag->id])
        ->call('addItem');

    $item = $this->playlist->items()->first();
    expect($item->fill_tags)->toBeEmpty();
});

test('user can add a jingle with relative offset in mm:ss format', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Stunden-Jingle',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/jingle.mp3',
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'jingle')
        ->set('addMode', 'library')
        ->set('selectedMediaFileId', $file->id)
        ->set('newRelativeOffset', '15:00')
        ->call('addItem');

    expect($this->playlist->items()->first()->relative_offset_seconds)->toBe(900);
});

test('relative offset in pure seconds is also accepted', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Jingle',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/jingle.mp3',
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'jingle')
        ->set('addMode', 'library')
        ->set('selectedMediaFileId', $file->id)
        ->set('newRelativeOffset', '300')
        ->call('addItem');

    expect($this->playlist->items()->first()->relative_offset_seconds)->toBe(300);
});

test('relative offset is optional', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('newType', 'music')
        ->set('addMode', 'library')
        ->set('selectedMediaFileId', $file->id)
        ->call('addItem');

    expect($this->playlist->items()->first()->relative_offset_seconds)->toBeNull();
});
