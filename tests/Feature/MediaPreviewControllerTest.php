<?php

use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

function fakeMediaFile(Station $station): MediaFile
{
    $file = MediaFile::factory()->create(['tenant_id' => $station->tenant_id]);
    Storage::disk('local')->put($file->file_path, 'fake-audio-bytes');

    return $file;
}

test('a user can preview a file of their own station', function () {
    $file = fakeMediaFile($this->station);

    $this->get(route('media.preview', $file))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');
});

test('a file uploaded through a sibling station can be previewed', function () {
    $sibling = Station::factory()->create(['user_id' => $this->user->id]);
    $file = fakeMediaFile($sibling);

    $this->get(route('media.preview', $file))->assertOk();
});

test('a file of a station the user cannot access is forbidden', function () {
    $foreign = Station::factory()->create(['user_id' => User::factory()->create()->id]);
    $file = fakeMediaFile($foreign);

    $this->get(route('media.preview', $file))->assertForbidden();
});

test('a missing file on disk returns 404', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    $this->get(route('media.preview', $file))->assertNotFound();
});

test('unauthenticated requests are redirected', function () {
    $file = fakeMediaFile($this->station);
    auth()->logout();

    $this->get(route('media.preview', $file))->assertRedirect();
});
