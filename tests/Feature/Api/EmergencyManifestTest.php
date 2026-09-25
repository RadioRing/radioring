<?php

use App\Models\MediaFile;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->station = Station::factory()->create();
});

function emergencyMediaFile(Station $station, string $title, int $bytes = 8, array $attributes = []): MediaFile
{
    $file = MediaFile::factory()->create([
        'tenant_id' => $station->tenant_id,
        'title' => $title,
        'file_path' => "tenants/{$station->tenant_id}/media/".str($title)->slug().'.mp3',
        ...$attributes,
    ]);

    Storage::disk('local')->put($file->file_path, str_repeat('a', $bytes));

    return $file;
}

function manifestFor(Station $station): TestResponse
{
    return test()->withToken($station->api_token)
        ->getJson("/api/liquidsoap/{$station->slug}/emergency");
}

test('the manifest rejects a request without a token', function () {
    $this->getJson("/api/liquidsoap/{$this->station->slug}/emergency")->assertUnauthorized();
});

test('the manifest names every selected file with a signed url', function () {
    $file = emergencyMediaFile($this->station, 'Technical fault', 16);
    $this->station->emergencyItems()->attach($file->id, ['position' => 0]);

    $entry = manifestFor($this->station)->assertOk()->json('files.0');

    expect($entry['name'])->toBe($file->id.'-'.$file->updated_at->timestamp.'.mp3')
        ->and($entry['bytes'])->toBe(16)
        ->and($entry['url'])->toContain("/stream/media/{$this->station->slug}/{$file->id}")
        ->and($entry['url'])->toContain('signature=');
});

test('the name changes when the file is replaced, which is what makes the container refetch it', function () {
    $file = emergencyMediaFile($this->station, 'Jingle');
    $this->station->emergencyItems()->attach($file->id, ['position' => 0]);

    $before = manifestFor($this->station)->json('files.0.name');

    $this->travel(1)->hour();
    $file->touch();

    expect(manifestFor($this->station)->json('files.0.name'))->not->toBe($before);
});

test('the manifest carries the offline measured gain and leaves it out when unmeasured', function () {
    config()->set('radioring.loudness.target_lufs', -14.0);

    $measured = emergencyMediaFile($this->station, 'Measured', 8, [
        'loudness_lufs' => -18.0,
        'loudness_true_peak' => -6.0,
    ]);
    $unmeasured = emergencyMediaFile($this->station, 'Unmeasured');

    $this->station->emergencyItems()->attach([
        $measured->id => ['position' => 0],
        $unmeasured->id => ['position' => 1],
    ]);

    $files = manifestFor($this->station)->json('files');

    expect($files[0]['amplify'])->toBe('4 dB')
        ->and($files[1]['amplify'])->toBeNull();
});

test('the manifest leaves out a file that is gone from disk', function () {
    $file = emergencyMediaFile($this->station, 'Deleted on disk');
    $this->station->emergencyItems()->attach($file->id, ['position' => 0]);

    Storage::disk('local')->delete($file->file_path);

    expect(manifestFor($this->station)->json('files'))->toBe([]);
});

test('the manifest honours the file count cap', function () {
    config()->set('radioring.emergency.max_files', 2);

    foreach (range(1, 3) as $index) {
        $this->station->emergencyItems()->attach(
            emergencyMediaFile($this->station, "Track {$index}")->id,
            ['position' => $index],
        );
    }

    expect(manifestFor($this->station)->json('files'))->toHaveCount(2);
});

test('the manifest honours the size cap', function () {
    config()->set('radioring.emergency.max_bytes', 30);

    $small = emergencyMediaFile($this->station, 'Small', 10);
    $huge = emergencyMediaFile($this->station, 'Huge', 100);

    $this->station->emergencyItems()->attach([
        $small->id => ['position' => 0],
        $huge->id => ['position' => 1],
    ]);

    $files = manifestFor($this->station)->json('files');

    expect($files)->toHaveCount(1)
        ->and($files[0]['bytes'])->toBe(10);
});

test('the manifest of one station never carries the selection of another', function () {
    $other = Station::factory()->create();
    $file = emergencyMediaFile($other, 'Theirs');
    $other->emergencyItems()->attach($file->id, ['position' => 0]);

    expect(manifestFor($this->station)->json('files'))->toBe([]);
});

test('fetching the manifest records when the container last synced', function () {
    expect($this->station->liquidsoapState)->toBeNull();

    manifestFor($this->station)->assertOk();

    expect($this->station->fresh()->liquidsoapState->emergency_synced_at)->not->toBeNull();
});
