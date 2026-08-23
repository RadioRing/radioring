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
    $this->stationA = Station::factory()->create(['user_id' => $this->owner->id]);
    $this->stationB = Station::factory()->create(['user_id' => $this->owner->id]);

    session(['current_station_id' => $this->stationB->id]);
    $this->actingAs($this->owner);
});

test('the library lists files uploaded through a sibling station', function () {
    $fromA = MediaFile::factory()->create([
        'tenant_id' => $this->stationA->tenant_id,
        'title' => 'Track from A',
    ]);

    Livewire::test(Index::class)->assertSee('Track from A');

    expect($this->stationB->poolMediaFiles()->pluck('id'))->toContain($fromA->id);
});

test('the library never lists files of another tenant', function () {
    $stranger = Station::factory()->create();
    MediaFile::factory()->create([
        'tenant_id' => $stranger->tenant_id,
        'title' => 'Someone Elses Track',
    ]);

    Livewire::test(Index::class)->assertDontSee('Someone Elses Track');
});

test('an editor cannot delete a file through the component', function () {
    $editor = User::factory()->create();
    $this->stationB->members()->attach($editor->id, ['role' => 'editor']);

    $file = MediaFile::factory()->create(['tenant_id' => $this->stationB->tenant_id]);

    $this->actingAs($editor);
    session(['current_station_id' => $this->stationB->id]);

    Livewire::test(Index::class)
        ->call('delete', $file->id)
        ->assertForbidden();

    expect(MediaFile::find($file->id))->not->toBeNull();
});

test('an owner deleting a file removes it from every station of the tenant', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->stationA->tenant_id]);

    Livewire::test(Index::class)->call('delete', $file->id);

    expect(MediaFile::find($file->id))->toBeNull()
        ->and($this->stationA->poolMediaFiles()->pluck('id'))->not->toContain($file->id);
});

test('tags created through one station are visible from its sibling', function () {
    Livewire::test(Index::class)
        ->set('newTagName', 'Rock')
        ->call('createTag');

    expect($this->stationA->tags()->pluck('name'))->toContain('Rock')
        ->and($this->stationB->tags()->pluck('name'))->toContain('Rock');
});
