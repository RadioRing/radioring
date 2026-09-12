<?php

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Livewire\MediaLibrary\Index;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

test('addPendingUpload appends an entry for the current station', function () {
    $path = "tenants/{$this->station->tenant_id}/media/abc_song.mp3";
    Storage::disk('local')->put($path, 'data');

    Livewire::test(Index::class)
        ->call('addPendingUpload', $path, 'Mein Song', 210, 'original.mp3')
        ->assertSet('pendingUploads.0.title', 'Mein Song')
        ->assertSet('pendingUploads.0.duration', 210)
        ->assertSet('pendingUploads.0.type', 'music');
});

test('addPendingUpload falls back to filename when title is empty', function () {
    $path = "tenants/{$this->station->tenant_id}/media/abc_cool-track.mp3";
    Storage::disk('local')->put($path, 'data');

    Livewire::test(Index::class)
        ->call('addPendingUpload', $path, '', null, 'cool-track.mp3')
        ->assertSet('pendingUploads.0.title', 'cool-track');
});

test('addPendingUpload rejects paths outside the station directory', function () {
    $foreignPath = 'stations/other-station/media/hack.mp3';

    Livewire::test(Index::class)
        ->call('addPendingUpload', $foreignPath, 'Hack', null, 'hack.mp3')
        ->assertSet('pendingUploads', []);
});

test('removePending deletes the file and removes the entry', function () {
    $path = "tenants/{$this->station->tenant_id}/media/abc_song.mp3";
    Storage::disk('local')->put($path, 'data');

    Livewire::test(Index::class)
        ->call('addPendingUpload', $path, 'Song', 180, 'song.mp3')
        ->call('removePending', 0)
        ->assertSet('pendingUploads', []);

    Storage::disk('local')->assertMissing($path);
});

test('save creates DB records, dispatches loudness analysis and clears the pending list', function () {
    Bus::fake();

    $pathA = "tenants/{$this->station->tenant_id}/media/a_track.mp3";
    $pathB = "tenants/{$this->station->tenant_id}/media/b_track.mp3";
    Storage::disk('local')->put($pathA, 'data');
    Storage::disk('local')->put($pathB, 'data');

    Livewire::test(Index::class)
        ->call('addPendingUpload', $pathA, 'Track A', 200, 'a.mp3')
        ->call('addPendingUpload', $pathB, 'Track B', 180, 'b.mp3')
        ->set('pendingUploads.1.type', 'jingle')
        ->call('save');

    expect($this->station->mediaFiles()->count())->toBe(2);

    $a = $this->station->mediaFiles()->where('title', 'Track A')->first();
    expect($a->type)->toBe('music')->and($a->duration_seconds)->toBe(200);

    $b = $this->station->mediaFiles()->where('title', 'Track B')->first();
    expect($b->type)->toBe('jingle');

    // Jede neue Datei wird zur Offline-Lautheitsmessung eingeplant.
    Bus::assertDispatchedTimes(AnalyzeMediaLoudnessJob::class, 2);
});

test('save validates that title is not empty', function () {
    $path = "tenants/{$this->station->tenant_id}/media/abc_song.mp3";
    Storage::disk('local')->put($path, 'data');

    Livewire::test(Index::class)
        ->call('addPendingUpload', $path, 'Song', null, 'song.mp3')
        ->set('pendingUploads.0.title', '')
        ->call('save')
        ->assertHasErrors(['pendingUploads.0.title']);
});

test('cancelUpload deletes assembled files and hides the form', function () {
    $path = "tenants/{$this->station->tenant_id}/media/abc_song.mp3";
    Storage::disk('local')->put($path, 'data');

    Livewire::test(Index::class)
        ->call('addPendingUpload', $path, 'Song', null, 'song.mp3')
        ->call('cancelUpload')
        ->assertSet('showUploadForm', false)
        ->assertSet('pendingUploads', []);

    Storage::disk('local')->assertMissing($path);
});

test('tags picked in the upload form land on every saved file', function () {
    Bus::fake();

    $jingles = $this->station->tags()->create(['name' => 'Jingles']);
    $morning = $this->station->tags()->create(['name' => 'Morgenshow']);

    $pathA = "tenants/{$this->station->tenant_id}/media/a_track.mp3";
    $pathB = "tenants/{$this->station->tenant_id}/media/b_track.mp3";
    Storage::disk('local')->put($pathA, 'data');
    Storage::disk('local')->put($pathB, 'data');

    Livewire::test(Index::class)
        ->call('addPendingUpload', $pathA, 'Track A', 200, 'a.mp3')
        ->call('addPendingUpload', $pathB, 'Track B', 180, 'b.mp3')
        ->set('uploadTagIds', [(string) $jingles->id, (string) $morning->id])
        ->call('save')
        ->assertSet('uploadTagIds', []);

    foreach (['Track A', 'Track B'] as $title) {
        $file = $this->station->mediaFiles()->where('title', $title)->first();
        expect($file->tags->pluck('id')->all())
            ->toEqualCanonicalizing([$jingles->id, $morning->id]);
    }
});

test('a tag foreign to the tenant is ignored on upload', function () {
    Bus::fake();

    $foreignStation = Station::factory()->create();
    $foreignTag = $foreignStation->tags()->create(['name' => 'Fremd']);

    $path = "tenants/{$this->station->tenant_id}/media/a_track.mp3";
    Storage::disk('local')->put($path, 'data');

    Livewire::test(Index::class)
        ->call('addPendingUpload', $path, 'Track A', 200, 'a.mp3')
        ->set('uploadTagIds', [(string) $foreignTag->id])
        ->call('save');

    expect($this->station->mediaFiles()->first()->tags)->toBeEmpty();
});

test('a new tag can be created straight from the upload form and is preselected', function () {
    Livewire::test(Index::class)
        ->set('newUploadTagName', 'Nachrichten')
        ->call('createUploadTag')
        ->assertHasNoErrors()
        ->assertSet('newUploadTagName', '');

    $tag = $this->station->tags()->where('name', 'Nachrichten')->first();
    expect($tag)->not->toBeNull();
});
