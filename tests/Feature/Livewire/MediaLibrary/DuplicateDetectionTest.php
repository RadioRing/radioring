<?php

use App\Livewire\MediaLibrary\Index;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->owner->id]);

    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->owner);
});

test('files with the same artist and title are detected as duplicates regardless of case and spacing', function () {
    $a = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'artist' => 'Adele', 'title' => 'Hello']);
    $b = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'artist' => '  adele ', 'title' => 'HELLO']);
    $unique = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'artist' => 'Queen', 'title' => 'Bohemian Rhapsody']);

    $ids = Livewire::test(Index::class)->instance()->duplicateFileIds();

    expect($ids)->toContain($a->id)->toContain($b->id)->not->toContain($unique->id);
});

test('a single file with a unique artist and title is not a duplicate', function () {
    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'artist' => 'Queen', 'title' => 'Bohemian Rhapsody']);

    expect(Livewire::test(Index::class)->instance()->duplicateFileIds())->toBeEmpty();
});

test('duplicates of another tenant do not count', function () {
    $stranger = Station::factory()->create();
    MediaFile::factory()->create(['tenant_id' => $stranger->tenant_id, 'artist' => 'Adele', 'title' => 'Hello']);
    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'artist' => 'Adele', 'title' => 'Hello']);

    expect(Livewire::test(Index::class)->instance()->duplicateFileIds())->toBeEmpty();
});

test('the same track uploaded through two sibling stations counts as a duplicate', function () {
    // Sibling stations share one library, so this really is the same file twice.
    $sibling = Station::factory()->create(['user_id' => $this->owner->id]);
    $a = MediaFile::factory()->create(['tenant_id' => $sibling->tenant_id, 'artist' => 'Adele', 'title' => 'Hello']);
    $b = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'artist' => 'Adele', 'title' => 'Hello']);

    expect(Livewire::test(Index::class)->instance()->duplicateFileIds())
        ->toContain($a->id)
        ->toContain($b->id);
});

test('the duplicate filter limits the list to duplicate files', function () {
    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'artist' => 'Adele', 'title' => 'Hello']);
    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'artist' => 'Adele', 'title' => 'Hello']);
    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'artist' => 'Queen', 'title' => 'Bohemian Rhapsody']);

    Livewire::test(Index::class)
        ->set('filterDuplicates', true)
        ->assertSee('Hello')
        ->assertDontSee('Bohemian Rhapsody');
});
