<?php

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Livewire\Playlist\Manager;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

/** Adds three plain items so ordering can be checked. */
function seedItems(): array
{
    $titles = ['A', 'B', 'C'];
    $ids = [];

    foreach ($titles as $index => $title) {
        $ids[$title] = test()->playlist->items()->create([
            'position' => $index,
            'type' => 'adbreak',
            'title' => $title,
        ])->id;
    }

    return $ids;
}

test('an element is duplicated right behind the original', function () {
    $ids = seedItems();

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('duplicateItem', $ids['B']);

    expect($this->playlist->items()->orderBy('position')->pluck('title')->all())
        ->toBe(['A', 'B', 'B', 'C'])
        ->and($this->playlist->items()->orderBy('position')->pluck('position')->all())
        ->toBe([0, 1, 2, 3]);
});

test('duplicating copies the settings of the element', function () {
    $tag = $this->station->tags()->create(['name' => 'Jingles']);
    $item = $this->playlist->items()->create([
        'position' => 0,
        'type' => 'fill',
        'title' => 'Auffüllen mit Musik',
        'fill_tags' => [$tag->id],
        'fill_max_duration_seconds' => 600,
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('duplicateItem', $item->id);

    $copy = $this->playlist->items()->orderBy('position')->get()->last();

    expect($copy->id)->not->toBe($item->id)
        ->and($copy->type)->toBe('fill')
        ->and($copy->fill_tags)->toBe([$tag->id])
        ->and($copy->fill_max_duration_seconds)->toBe(600);
});

test('several elements can be duplicated at once, each behind itself', function () {
    $ids = seedItems();

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('selectedItemIds', [(string) $ids['A'], (string) $ids['C']])
        ->call('duplicateSelected')
        ->assertSet('selectedItemIds', []);

    expect($this->playlist->items()->orderBy('position')->pluck('title')->all())
        ->toBe(['A', 'A', 'B', 'C', 'C']);
});

test('selected elements can be removed at once', function () {
    $ids = seedItems();

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('selectedItemIds', [(string) $ids['A'], (string) $ids['B']])
        ->call('removeSelected')
        ->assertSet('selectedItemIds', []);

    expect($this->playlist->items()->orderBy('position')->pluck('title')->all())->toBe(['C'])
        ->and($this->playlist->items()->first()->position)->toBe(0);
});

test('select all ticks every element', function () {
    $ids = seedItems();

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('selectAllItems')
        ->assertSet('selectedItemIds', array_map('strval', array_values($ids)))
        ->call('clearSelection')
        ->assertSet('selectedItemIds', []);
});

test('elements of another playlist are never touched by the bulk actions', function () {
    $other = $this->station->playlists()->create(['name' => 'Other', 'playback_mode' => 'sequential']);
    $foreignItem = $other->items()->create(['position' => 0, 'type' => 'adbreak', 'title' => 'Foreign']);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('selectedItemIds', [(string) $foreignItem->id])
        ->call('removeSelected');

    expect($other->items()->count())->toBe(1);
});

test('a legacy file is kept as long as a copy still points at it', function () {
    Storage::fake('local');
    Storage::disk('local')->put('legacy/old.mp3', 'audio');

    $item = $this->playlist->items()->create([
        'position' => 0,
        'type' => 'music',
        'title' => 'Legacy',
        'file_path' => 'legacy/old.mp3',
    ]);

    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('duplicateItem', $item->id);

    $component->call('removeItem', $item->id);
    Storage::disk('local')->assertExists('legacy/old.mp3');

    $copy = $this->playlist->items()->first();
    $component->call('removeItem', $copy->id);
    Storage::disk('local')->assertMissing('legacy/old.mp3');
});

test('an upload from the editor lands in the tenant library and is analysed', function () {
    Storage::fake('local');
    Queue::fake();

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('uploadType', 'jingle')
        ->set('uploadTitle', 'Station Jingle')
        ->set('uploadFile', UploadedFile::fake()->create('jingle.mp3', 120, 'audio/mpeg'))
        ->call('submitUpload');

    $mediaFile = $this->station->mediaFiles()->first();

    expect($mediaFile)->not->toBeNull()
        ->and($mediaFile->file_path)->toStartWith("tenants/{$this->station->tenant_id}/media/")
        ->and($mediaFile->type)->toBe('jingle');

    Storage::disk('local')->assertExists($mediaFile->file_path);
    Queue::assertPushed(AnalyzeMediaLoudnessJob::class);

    expect($this->playlist->items()->first()->media_file_id)->toBe($mediaFile->id);
});

test('the palette keeps its type filter while searching', function () {
    $this->station->mediaFiles()->create([
        'title' => 'Sunrise', 'type' => 'music',
        'file_path' => 'tenants/t/media/sunrise.mp3', 'duration_seconds' => 200,
    ]);
    $this->station->mediaFiles()->create([
        'title' => 'Sunrise Jingle', 'type' => 'jingle',
        'file_path' => 'tenants/t/media/sunrise-jingle.mp3', 'duration_seconds' => 8,
    ]);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('paletteMediaType', 'jingle')
        ->set('paletteSearch', 'Sunrise')
        ->assertViewHas('paletteEntries', fn ($entries) => $entries->pluck('title')->all() === ['Sunrise Jingle']);
});
