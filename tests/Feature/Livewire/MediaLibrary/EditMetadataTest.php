<?php

use App\Livewire\MediaLibrary\Index;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

test('a file title and artist can be edited', function () {
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'title' => 'Alt',
        'artist' => 'Alter Interpret',
    ]);

    Livewire::test(Index::class)
        ->call('startEditingFile', $file->id)
        ->assertSet('editTitle', 'Alt')
        ->assertSet('editArtist', 'Alter Interpret')
        ->set('editTitle', 'Neu')
        ->set('editArtist', 'Neuer Interpret')
        ->call('saveFileEdit')
        ->assertHasNoErrors()
        ->assertSet('editingFileId', null);

    expect($file->fresh())
        ->title->toBe('Neu')
        ->artist->toBe('Neuer Interpret');
});

test('fade-in can be toggled on and is loaded back into the form', function () {
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'fade_in' => false,
    ]);

    Livewire::test(Index::class)
        ->call('startEditingFile', $file->id)
        ->assertSet('editFadeIn', false)
        ->set('editFadeIn', true)
        ->call('saveFileEdit')
        ->assertHasNoErrors();

    expect($file->fresh()->fade_in)->toBeTrue();

    Livewire::test(Index::class)
        ->call('startEditingFile', $file->id)
        ->assertSet('editFadeIn', true);
});

test('the artist can be cleared back to null', function () {
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'artist' => 'Wird gelöscht',
    ]);

    Livewire::test(Index::class)
        ->call('startEditingFile', $file->id)
        ->set('editArtist', '')
        ->call('saveFileEdit')
        ->assertHasNoErrors();

    expect($file->fresh()->artist)->toBeNull();
});

test('the title is required when editing', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    Livewire::test(Index::class)
        ->call('startEditingFile', $file->id)
        ->set('editTitle', '')
        ->call('saveFileEdit')
        ->assertHasErrors('editTitle');
});

test('files from another station cannot be edited', function () {
    $other = Station::factory()->create();
    $file = MediaFile::factory()->create(['tenant_id' => $other->tenant_id]);

    Livewire::test(Index::class)
        ->call('startEditingFile', $file->id);
})->throws(ModelNotFoundException::class);

test('uploaded files keep the artist from id3 tags', function () {
    $path = "tenants/{$this->station->tenant_id}/media/song.mp3";

    Livewire::test(Index::class)
        ->call('addPendingUpload', $path, 'Mein Song', 200, 'song.mp3', 'Die Band')
        ->assertSet('pendingUploads.0.artist', 'Die Band')
        ->call('save')
        ->assertHasNoErrors();

    expect(MediaFile::where('tenant_id', $this->station->tenant_id)->first())
        ->title->toBe('Mein Song')
        ->artist->toBe('Die Band');
});

test('files can be searched by artist', function () {
    MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'title' => 'Irgendwas',
        'artist' => 'Queen',
    ]);
    MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'title' => 'Anderes',
        'artist' => 'ABBA',
    ]);

    Livewire::test(Index::class)
        ->set('search', 'Queen')
        ->assertSee('Irgendwas')
        ->assertDontSee('Anderes');
});
