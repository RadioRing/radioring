<?php

use App\Livewire\MediaLibrary\Index;
use App\Models\GeneratedPlaylist;
use App\Models\PlaylistItem;
use App\Models\Station;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

test('user can create a tag', function () {
    Livewire::test(Index::class)
        ->set('newTagName', '80er')
        ->call('createTag');

    expect($this->station->tags()->where('name', '80er')->exists())->toBeTrue();
});

test('duplicate tag names within same station are not created twice', function () {
    $this->station->tags()->create(['name' => '80er']);

    Livewire::test(Index::class)
        ->set('newTagName', '80er')
        ->call('createTag');

    expect($this->station->tags()->where('name', '80er')->count())->toBe(1);
});

test('user can delete a tag', function () {
    $tag = $this->station->tags()->create(['name' => 'Oldies']);

    Livewire::test(Index::class)
        ->call('deleteTag', $tag->id);

    expect(Tag::find($tag->id))->toBeNull();
});

test('deleting a tag removes it from all files', function () {
    $tag = $this->station->tags()->create(['name' => 'Pop']);
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
    ]);
    $file->tags()->attach($tag);

    Livewire::test(Index::class)
        ->call('deleteTag', $tag->id);

    expect($file->fresh()->tags()->count())->toBe(0);
});

test('user can assign tags to a media file', function () {
    $tag1 = $this->station->tags()->create(['name' => '80er']);
    $tag2 = $this->station->tags()->create(['name' => '90er']);
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
    ]);

    Livewire::test(Index::class)
        ->call('startEditingTags', $file->id)
        ->set('editingTagIds', [(string) $tag1->id, (string) $tag2->id])
        ->call('saveFileTags');

    expect($file->fresh()->tags->pluck('id')->toArray())
        ->toContain($tag1->id)
        ->toContain($tag2->id);
});

test('user can filter media files by tag', function () {
    $tag = $this->station->tags()->create(['name' => 'Rock']);
    $fileWithTag = $this->station->mediaFiles()->create([
        'title' => 'Rock Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/rock.mp3',
    ]);
    $fileWithTag->tags()->attach($tag);

    $this->station->mediaFiles()->create([
        'title' => 'Pop Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/pop.mp3',
    ]);

    Livewire::test(Index::class)
        ->set('filterTagId', (string) $tag->id)
        ->assertSee('Rock Song')
        ->assertDontSee('Pop Song');
});

test('user can filter media files without tags', function () {
    $tag = $this->station->tags()->create(['name' => 'Rock']);
    $tagged = $this->station->mediaFiles()->create([
        'title' => 'Tagged Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/tagged.mp3',
    ]);
    $tagged->tags()->attach($tag);

    $this->station->mediaFiles()->create([
        'title' => 'Untagged Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/untagged.mp3',
    ]);

    Livewire::test(Index::class)
        ->set('filterTagId', 'none')
        ->assertSee('Untagged Song')
        ->assertDontSee('Tagged Song');
});

test('deleting a media file removes it from playlist templates', function () {
    Storage::fake('local');

    $playlist = $this->station->playlists()->create([
        'name' => 'Morning Show',
        'playback_mode' => 'sequential',
    ]);
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
    ]);
    $item = $playlist->items()->create([
        'position' => 0,
        'type' => 'music',
        'title' => 'Song',
        'media_file_id' => $file->id,
    ]);

    Livewire::test(Index::class)
        ->call('delete', $file->id);

    expect(PlaylistItem::find($item->id))->toBeNull();
});

test('deleting a media file removes it from generated rundown items', function () {
    Storage::fake('local');

    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
    ]);
    $playlist = $this->station->playlists()->create([
        'name' => 'Show',
        'playback_mode' => 'sequential',
    ]);
    $slot = $this->station->hourGridSlots()->create([
        'weekday' => 0,
        'hour' => 10,
        'playlist_id' => $playlist->id,
    ]);
    $rundown = GeneratedPlaylist::create([
        'station_id' => $this->station->id,
        'hour_grid_slot_id' => $slot->id,
        'playlist_id' => $playlist->id,
        'broadcast_date' => today()->toDateString(),
        'broadcast_hour' => 10,
        'status' => 'ready',
        'generated_at' => now(),
    ]);
    $rundownItem = $rundown->items()->create([
        'position' => 0,
        'media_file_id' => $file->id,
        'title' => 'Song',
        'source_type' => 'resolved_fill',
    ]);

    Livewire::test(Index::class)
        ->call('delete', $file->id);

    expect($rundownItem->fresh())->toBeNull();
});

test('user can bulk add a tag to selected files', function () {
    $tag = $this->station->tags()->create(['name' => 'Rock']);
    $file1 = $this->station->mediaFiles()->create([
        'title' => 'A', 'type' => 'music', 'file_path' => 'tenants/test/media/a.mp3',
    ]);
    $file2 = $this->station->mediaFiles()->create([
        'title' => 'B', 'type' => 'music', 'file_path' => 'tenants/test/media/b.mp3',
    ]);

    Livewire::test(Index::class)
        ->set('selectedFileIds', [$file1->id, $file2->id])
        ->set('bulkTagId', (string) $tag->id)
        ->call('bulkAddTag');

    expect($file1->fresh()->tags->pluck('id'))->toContain($tag->id);
    expect($file2->fresh()->tags->pluck('id'))->toContain($tag->id);
});

test('bulk add tag does not duplicate existing assignments', function () {
    $tag = $this->station->tags()->create(['name' => 'Rock']);
    $file = $this->station->mediaFiles()->create([
        'title' => 'A', 'type' => 'music', 'file_path' => 'tenants/test/media/a.mp3',
    ]);
    $file->tags()->attach($tag);

    Livewire::test(Index::class)
        ->set('selectedFileIds', [$file->id])
        ->set('bulkTagId', (string) $tag->id)
        ->call('bulkAddTag');

    expect($file->fresh()->tags()->count())->toBe(1);
});

test('user can bulk remove a tag from selected files', function () {
    $tag = $this->station->tags()->create(['name' => 'Rock']);
    $file1 = $this->station->mediaFiles()->create([
        'title' => 'A', 'type' => 'music', 'file_path' => 'tenants/test/media/a.mp3',
    ]);
    $file2 = $this->station->mediaFiles()->create([
        'title' => 'B', 'type' => 'music', 'file_path' => 'tenants/test/media/b.mp3',
    ]);
    $file1->tags()->attach($tag);
    $file2->tags()->attach($tag);

    Livewire::test(Index::class)
        ->set('selectedFileIds', [$file1->id, $file2->id])
        ->set('bulkTagId', (string) $tag->id)
        ->call('bulkRemoveTag');

    expect($file1->fresh()->tags()->count())->toBe(0);
    expect($file2->fresh()->tags()->count())->toBe(0);
});

test('bulk tagging requires a selected tag', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'A', 'type' => 'music', 'file_path' => 'tenants/test/media/a.mp3',
    ]);

    Livewire::test(Index::class)
        ->set('selectedFileIds', [$file->id])
        ->set('bulkTagId', '')
        ->call('bulkAddTag')
        ->assertHasErrors('bulkTagId');
});

test('bulk tagging ignores tags from another station', function () {
    $otherStation = Station::factory()->create();
    $foreignTag = $otherStation->tags()->create(['name' => 'Fremd']);
    $file = $this->station->mediaFiles()->create([
        'title' => 'A', 'type' => 'music', 'file_path' => 'tenants/test/media/a.mp3',
    ]);

    Livewire::test(Index::class)
        ->set('selectedFileIds', [$file->id])
        ->set('bulkTagId', (string) $foreignTag->id)
        ->call('bulkAddTag');

    expect($file->fresh()->tags()->count())->toBe(0);
});

test('select all toggles all visible files', function () {
    $file1 = $this->station->mediaFiles()->create([
        'title' => 'A', 'type' => 'music', 'file_path' => 'tenants/test/media/a.mp3',
    ]);
    $file2 = $this->station->mediaFiles()->create([
        'title' => 'B', 'type' => 'music', 'file_path' => 'tenants/test/media/b.mp3',
    ]);

    Livewire::test(Index::class)
        ->call('toggleSelectAll')
        ->assertSet('selectedFileIds', [$file1->id, $file2->id])
        ->call('toggleSelectAll')
        ->assertSet('selectedFileIds', []);
});

test('only tags from own station can be assigned', function () {
    $otherStation = Station::factory()->create();
    $foreignTag = $otherStation->tags()->create(['name' => 'Fremd']);
    $file = $this->station->mediaFiles()->create([
        'title' => 'Song',
        'type' => 'music',
        'file_path' => 'tenants/test/media/song.mp3',
    ]);

    Livewire::test(Index::class)
        ->call('startEditingTags', $file->id)
        ->set('editingTagIds', [(string) $foreignTag->id])
        ->call('saveFileTags');

    expect($file->fresh()->tags()->count())->toBe(0);
});
