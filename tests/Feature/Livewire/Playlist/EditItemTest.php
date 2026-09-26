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

test('user can move a fixed time marker and make it hard', function () {
    $marker = $this->playlist->items()->create([
        'position' => 0, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => 300, 'fixed_mode' => 'soft',
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('startEditingItem', $marker->id)
        ->assertSet('editRelativeOffset', '05:00')
        ->assertSet('editFixedMode', 'soft')
        ->set('editRelativeOffset', '15:00')
        ->set('editFixedMode', 'hard')
        ->call('saveItem')
        ->assertHasNoErrors();

    expect($marker->fresh()->relative_offset_seconds)->toBe(900)
        ->and($marker->fresh()->fixed_mode)->toBe('hard');
});

test('a soft marker is yellow and a hard one red', function () {
    $marker = $this->playlist->items()->create([
        'position' => 0, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => 900, 'fixed_mode' => 'soft',
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->assertSeeHtml('list-group-item-warning')
        ->assertDontSeeHtml('list-group-item-danger')
        ->assertSee('Soft fixed time 15:00');

    $marker->update(['fixed_mode' => 'hard']);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->assertSeeHtml('list-group-item-danger')
        ->assertSee('Hard fixed time 15:00');
});

test('a marker time has to lie within the hour', function () {
    $marker = $this->playlist->items()->create([
        'position' => 0, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => 0, 'fixed_mode' => 'soft',
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('startEditingItem', $marker->id)
        ->set('editRelativeOffset', '60:00')
        ->call('saveItem')
        ->assertHasErrors('editRelativeOffset');

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('startEditingItem', $marker->id)
        ->set('editRelativeOffset', 'soon')
        ->call('saveItem')
        ->assertHasErrors('editRelativeOffset');

    expect($marker->fresh()->relative_offset_seconds)->toBe(0);
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
