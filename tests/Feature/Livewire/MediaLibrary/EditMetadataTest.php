<?php

use App\Livewire\MediaLibrary\FileModal;
use App\Livewire\MediaLibrary\Index;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
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

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->assertSet('title', 'Alt')
        ->assertSet('artist', 'Alter Interpret')
        ->set('title', 'Neu')
        ->set('artist', 'Neuer Interpret')
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh())
        ->title->toBe('Neu')
        ->artist->toBe('Neuer Interpret');
});

test('cue and fade points are saved and loaded back into the form', function () {
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'duration_seconds' => 200,
    ]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->assertSet('cueIn', '')
        ->assertSet('cueOut', '')
        ->set('cueIn', '3.256')
        ->set('cueOut', '190.5')
        ->set('fadeIn', '2')
        ->set('fadeOut', '4.25')
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh())
        ->cue_in_seconds->toBe(3.26)
        ->cue_out_seconds->toBe(190.5)
        ->fade_in_seconds->toBe(2.0)
        ->fade_out_seconds->toBe(4.25)
        ->playbackDuration()->toBe(187);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->assertSet('cueIn', '3.26')
        ->assertSet('cueOut', '190.5')
        ->assertSet('fadeIn', '2')
        ->assertSet('fadeOut', '4.25');
});

test('cue points that change nothing are stored as null', function () {
    $file = MediaFile::factory()->withCuePoints()->create([
        'tenant_id' => $this->station->tenant_id,
        'duration_seconds' => 200,
    ]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->set('cueIn', '0')
        ->set('cueOut', '200')
        ->set('fadeIn', '')
        ->set('fadeOut', '0')
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh())
        ->cue_in_seconds->toBeNull()
        ->cue_out_seconds->toBeNull()
        ->fade_in_seconds->toBeNull()
        ->fade_out_seconds->toBeNull();
});

test('cue points are checked against each other and the file length', function (array $fields, string $errorField) {
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'duration_seconds' => 200,
    ]);

    $component = Livewire::test(FileModal::class)->call('open', $file->id);

    foreach ($fields as $field => $value) {
        $component->set($field, $value);
    }

    $component->call('save')->assertHasErrors($errorField);

    expect($file->fresh()->cue_in_seconds)->toBeNull();
})->with([
    'cue in beyond the end' => [['cueIn' => '200'], 'cueIn'],
    'cue out beyond the end' => [['cueOut' => '205'], 'cueOut'],
    'cue out before cue in' => [['cueIn' => '50', 'cueOut' => '40'], 'cueOut'],
    'fades longer than the range' => [['cueIn' => '10', 'cueOut' => '20', 'fadeIn' => '6', 'fadeOut' => '5'], 'fadeOut'],
    'negative fade' => [['fadeIn' => '-1'], 'fadeIn'],
    'not a number' => [['cueIn' => 'abc'], 'cueIn'],
]);

test('a presenter cannot change the cue points of a file they did not upload', function () {
    $presenter = User::factory()->create();
    $this->station->members()->attach($presenter->id, ['role' => 'presenter']);
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    $this->actingAs($presenter);
    session(['current_station_id' => $this->station->id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->set('cueIn', '5')
        ->call('save')
        ->assertForbidden();

    expect($file->fresh()->cue_in_seconds)->toBeNull();
});

test('the artist can be cleared back to null', function () {
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'artist' => 'Wird gelöscht',
    ]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->set('artist', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh()->artist)->toBeNull();
});

test('the title is required when editing', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->set('title', '')
        ->call('save')
        ->assertHasErrors('title');
});

test('files from another station cannot be edited', function () {
    $other = Station::factory()->create();
    $file = MediaFile::factory()->create(['tenant_id' => $other->tenant_id, 'title' => 'Fremd']);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->assertSet('file', null)
        ->set('title', 'Gekapert')
        ->call('save');

    expect($file->fresh()->title)->toBe('Fremd');
});

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
