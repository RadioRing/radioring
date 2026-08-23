<?php

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Livewire\MediaLibrary\Show;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\MediaFile;
use App\Models\MediaFileVersion;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);

    Queue::fake();
});

/**
 * Legt eine Mediendatei samt echter Datei auf der local-Disk an.
 */
function mediaFileOnDisk(int $tenantId, string $name = 'alt.mp3', array $attributes = []): MediaFile
{
    $path = "tenants/{$tenantId}/media/{$name}";
    Storage::disk('local')->put($path, 'alte-fassung');

    return MediaFile::factory()->create([
        'tenant_id' => $tenantId,
        'file_path' => $path,
        ...$attributes,
    ]);
}

test('the detail page saves metadata and notes', function () {
    $file = mediaFileOnDisk($this->station->tenant_id);

    Livewire::test(Show::class, ['mediaFile' => $file])
        ->assertSet('title', $file->title)
        ->set('title', 'Neuer Titel')
        ->set('notes', 'Intro 8 Sekunden')
        ->set('type', 'jingle')
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh())
        ->title->toBe('Neuer Titel')
        ->notes->toBe('Intro 8 Sekunden')
        ->type->toBe('jingle');
});

test('a file of another tenant is not reachable', function () {
    $foreign = MediaFile::factory()->create();

    Livewire::test(Show::class, ['mediaFile' => $foreign])
        ->assertStatus(404);
});

test('replacing swaps the file, archives the old version and re-measures the loudness', function () {
    $file = mediaFileOnDisk($this->station->tenant_id, 'alt.mp3', [
        'loudness_lufs' => -12.5,
        'loudness_true_peak' => -1.5,
        'loudness_measured_at' => now(),
        'duration_seconds' => 200,
    ]);

    $newPath = "tenants/{$this->station->tenant_id}/media/neu.mp3";
    Storage::disk('local')->put($newPath, 'neue-fassung');

    Livewire::test(Show::class, ['mediaFile' => $file])
        ->call('addPendingReplacement', $newPath, 'Aus ID3', 240, 'neu.mp3')
        ->call('confirmReplacement')
        ->assertHasNoErrors()
        ->assertSet('pendingReplacement', null);

    expect($file->fresh())
        ->file_path->toBe($newPath)
        ->loudness_lufs->toBeNull()
        ->loudness_true_peak->toBeNull()
        ->loudness_measured_at->toBeNull();

    $version = MediaFileVersion::where('media_file_id', $file->id)->sole();

    expect($version)
        ->file_path->toBe("tenants/{$this->station->tenant_id}/media/alt.mp3")
        ->loudness_lufs->toBe(-12.5)
        ->duration_seconds->toBe(200)
        ->replaced_by_user_id->toBe($this->user->id);

    // Die alte Fassung bleibt liegen: laufende Rundowns spielen sie zu Ende.
    Storage::disk('local')->assertExists($version->file_path);

    Queue::assertPushed(AnalyzeMediaLoudnessJob::class);
});

test('an editor may not replace files', function () {
    $editor = User::factory()->create();
    $this->station->members()->attach($editor->id, ['role' => 'editor']);

    $file = mediaFileOnDisk($this->station->tenant_id);
    $newPath = "tenants/{$this->station->tenant_id}/media/neu.mp3";
    Storage::disk('local')->put($newPath, 'neue-fassung');

    $this->actingAs($editor);
    session(['current_station_id' => $this->station->id]);

    Livewire::test(Show::class, ['mediaFile' => $file])
        ->call('addPendingReplacement', $newPath, null, null, 'neu.mp3')
        ->assertStatus(403);
});

test('an already generated rundown keeps playing the replaced version', function () {
    $file = mediaFileOnDisk($this->station->tenant_id, 'alt.mp3', ['loudness_lufs' => -16.0]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'media_file_path' => $file->file_path,
        'loudness_lufs' => -16.0,
        'position' => 0,
        'title' => $file->title,
        'source_type' => 'template_item',
    ]);

    $newPath = "tenants/{$this->station->tenant_id}/media/neu.mp3";
    Storage::disk('local')->put($newPath, 'neue-fassung');

    Livewire::test(Show::class, ['mediaFile' => $file])
        ->call('addPendingReplacement', $newPath, null, 240, 'neu.mp3')
        ->call('confirmReplacement');

    expect($item->fresh()->supersededPath())->toBe("tenants/{$this->station->tenant_id}/media/alt.mp3");

    // Auslieferung an Liquidsoap: mit Item-Bezug die eingefrorene, ohne die neue Fassung.
    $signed = signedDeliveryUrl('liquidsoap.media', [
        'slug' => $this->station->slug,
        'mediaFile' => $file->id,
        'item' => $item->id,
    ]);

    $response = $this->get($signed);
    $response->assertOk();
    expect($response->streamedContent())->toBe('alte-fassung');

    $withoutItem = signedDeliveryUrl('liquidsoap.media', [
        'slug' => $this->station->slug,
        'mediaFile' => $file->id,
    ]);

    expect($this->get($withoutItem)->streamedContent())->toBe('neue-fassung');
});

test('a restored version becomes the current file again', function () {
    $file = mediaFileOnDisk($this->station->tenant_id, 'alt.mp3', ['duration_seconds' => 200]);

    $newPath = "tenants/{$this->station->tenant_id}/media/neu.mp3";
    Storage::disk('local')->put($newPath, 'neue-fassung');

    $component = Livewire::test(Show::class, ['mediaFile' => $file])
        ->call('addPendingReplacement', $newPath, null, 240, 'neu.mp3')
        ->call('confirmReplacement');

    $version = MediaFileVersion::where('media_file_id', $file->id)->sole();

    $component->call('restoreVersion', $version->id);

    expect($file->fresh())
        ->file_path->toBe("tenants/{$this->station->tenant_id}/media/alt.mp3")
        ->duration_seconds->toBe(200);

    // Die zwischenzeitlich aktuelle Fassung liegt jetzt im Archiv.
    expect(MediaFileVersion::where('media_file_id', $file->id)->sole()->file_path)->toBe($newPath);
});
