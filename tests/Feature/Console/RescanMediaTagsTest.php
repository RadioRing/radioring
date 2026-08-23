<?php

use App\Models\MediaFile;
use App\Models\Station;
use App\Services\AudioMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->station = Station::factory()->create();
});

/**
 * Legt einen MediaFile-Datensatz an und eine zugehörige (leere) Datei auf der Fake-Disk.
 */
function mediaWithFile(Station $station, array $attributes): MediaFile
{
    $path = "tenants/{$station->tenant_id}/media/".uniqid().'.mp3';
    Storage::disk('local')->put($path, 'fake-audio');

    return $station->mediaFiles()->create(array_merge([
        'title' => 'Untitled',
        'type' => 'music',
        'file_path' => $path,
    ], $attributes));
}

function mockMeta(array $meta): void
{
    test()->mock(AudioMetadataService::class, function ($mock) use ($meta) {
        $mock->shouldReceive('read')->andReturn(array_merge([
            'title' => null, 'artist' => null, 'album' => null, 'duration' => null,
        ], $meta));
    });
}

test('it fills empty fields from id3 tags', function () {
    $file = mediaWithFile($this->station, ['artist' => null, 'album' => null, 'duration_seconds' => null]);

    mockMeta(['title' => 'Tag Title', 'artist' => 'Tag Artist', 'album' => 'Tag Album', 'duration' => 222]);

    $this->artisan('media:rescan-tags')->assertSuccessful();

    expect($file->fresh())
        ->artist->toBe('Tag Artist')
        ->album->toBe('Tag Album')
        ->duration_seconds->toBe(222);
});

test('it does not overwrite existing values without force', function () {
    $file = mediaWithFile($this->station, ['artist' => 'Vorhanden', 'duration_seconds' => 100]);

    mockMeta(['artist' => 'Tag Artist', 'duration' => 999]);

    $this->artisan('media:rescan-tags')->assertSuccessful();

    expect($file->fresh())
        ->artist->toBe('Vorhanden')
        ->duration_seconds->toBe(100);
});

test('force overwrites existing values', function () {
    $file = mediaWithFile($this->station, ['artist' => 'Alt']);

    mockMeta(['artist' => 'Neu']);

    $this->artisan('media:rescan-tags --force')->assertSuccessful();

    expect($file->fresh()->artist)->toBe('Neu');
});

test('dry run changes nothing', function () {
    $file = mediaWithFile($this->station, ['artist' => null]);

    mockMeta(['artist' => 'Tag Artist']);

    $this->artisan('media:rescan-tags --dry-run')->assertSuccessful();

    expect($file->fresh()->artist)->toBeNull();
});

test('it can be scoped to one tenant library by station slug', function () {
    $other = Station::factory()->create();
    $mine = mediaWithFile($this->station, ['artist' => null]);
    $theirs = mediaWithFile($other, ['artist' => null]);

    mockMeta(['artist' => 'Tag Artist']);

    $this->artisan('media:rescan-tags', ['--station' => $this->station->slug])->assertSuccessful();

    expect($mine->fresh()->artist)->toBe('Tag Artist');
    expect($theirs->fresh()->artist)->toBeNull();
});

test('it reports an unknown station', function () {
    $this->artisan('media:rescan-tags --station=nope')->assertFailed();
});

test('missing files on disk are skipped, not crashed', function () {
    $file = $this->station->mediaFiles()->create([
        'title' => 'Ghost',
        'type' => 'music',
        'file_path' => "tenants/{$this->station->tenant_id}/media/missing.mp3",
        'artist' => null,
    ]);

    mockMeta(['artist' => 'Tag Artist']);

    $this->artisan('media:rescan-tags')->assertSuccessful();

    expect($file->fresh()->artist)->toBeNull();
});
