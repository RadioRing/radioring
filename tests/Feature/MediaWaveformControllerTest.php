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

function mediaFileWithWaveform(Station $station): MediaFile
{
    $file = MediaFile::factory()->create(['tenant_id' => $station->tenant_id]);
    $file->update(['waveform_path' => $file->waveformStoragePath()]);
    Storage::disk('local')->put($file->waveform_path, '{"version":1,"peaks":[1,2,3]}');

    return $file;
}

test('a user gets the waveform of a file of their own station', function () {
    $file = mediaFileWithWaveform($this->station);

    $response = $this->get(route('media.waveform', $file))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json');

    expect($response->baseResponse->getFile()->getContent())->toBe('{"version":1,"peaks":[1,2,3]}');
});

test('the waveform of a station the user cannot access is forbidden', function () {
    $foreign = Station::factory()->create(['user_id' => User::factory()->create()->id]);
    $file = mediaFileWithWaveform($foreign);

    $this->get(route('media.waveform', $file))->assertForbidden();
});

test('a file without generated waveform returns 404', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    $this->get(route('media.waveform', $file))->assertNotFound();
});
