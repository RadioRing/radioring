<?php

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\MediaFile;
use App\Models\MediaFileVersion;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->station = Station::factory()->create(['user_id' => User::factory()->create()->id]);
});

/**
 * Archivierte Fassung samt Datei auf der Disk.
 */
function replacedVersion(MediaFile $file, string $name, ?DateTimeInterface $replacedAt = null): MediaFileVersion
{
    $path = "tenants/{$file->tenant_id}/media/{$name}";
    Storage::disk('local')->put($path, 'alte-fassung');

    $version = MediaFileVersion::create([
        'media_file_id' => $file->id,
        'file_path' => $path,
    ]);

    if ($replacedAt) {
        $version->forceFill(['created_at' => $replacedAt])->save();
    }

    return $version;
}

test('an old version without any rundown reference is deleted', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
    $version = replacedVersion($file, 'alt.mp3', now()->subDays(30));

    $this->artisan('media:prune-replaced')->assertSuccessful();

    Storage::disk('local')->assertMissing($version->file_path);
    expect(MediaFileVersion::count())->toBe(0);
});

test('a version a pending rundown still points to is kept', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
    $version = replacedVersion($file, 'alt.mp3', now()->subDays(30));

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 12,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'media_file_path' => $version->file_path,
        'position' => 0,
        'title' => $file->title,
    ]);

    $this->artisan('media:prune-replaced')->assertSuccessful();

    Storage::disk('local')->assertExists($version->file_path);
    expect(MediaFileVersion::count())->toBe(1);

    // Sobald der Rundown gesendet ist, darf die Fassung weg.
    $rundown->update(['status' => 'played']);

    $this->artisan('media:prune-replaced')->assertSuccessful();

    Storage::disk('local')->assertMissing($version->file_path);
});

test('a freshly replaced version stays within the retention window', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
    $version = replacedVersion($file, 'alt.mp3');

    $this->artisan('media:prune-replaced')->assertSuccessful();

    Storage::disk('local')->assertExists($version->file_path);
});
