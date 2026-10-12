<?php

use App\Models\MediaFile;
use App\Models\MediaFileVersion;
use App\Models\Station;
use App\Models\User;
use App\Services\MediaReplacementService;
use App\Services\RundownGeneratorService;
use App\Support\PlaylistElements\ElementLengths;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
});

test('the playback duration runs from cue in to cue out', function (?int $length, ?float $cueIn, ?float $cueOut, ?int $expected) {
    $file = new MediaFile([
        'duration_seconds' => $length,
        'cue_in_seconds' => $cueIn,
        'cue_out_seconds' => $cueOut,
    ]);

    expect($file->playbackDuration())->toBe($expected);
})->with([
    'untouched' => [200, null, null, 200],
    'cue in only' => [200, 12.4, null, 188],
    'cue out only' => [200, null, 180.0, 180],
    'both' => [200, 5.0, 190.6, 186],
    'cue out past the rounded length' => [200, null, 200.4, 200],
    'unknown length' => [null, null, null, null],
    'unknown length, cue out set' => [null, 2.0, 62.0, 60],
]);

test('cue and fade points become liquidsoap annotations with a decimal point', function () {
    $file = new MediaFile([
        'duration_seconds' => 200,
        'cue_in_seconds' => 3.0,
        'cue_out_seconds' => 190.25,
        'fade_in_seconds' => 1.5,
        'fade_out_seconds' => 0.0,
    ]);

    expect($file->cueAnnotations())->toBe(['liq_cue_in' => '3.00', 'liq_cue_out' => '190.25'])
        ->and($file->fadeAnnotations())->toBe(['liq_fade_in' => '1.50']);
});

test('a rundown times its elements by the playback duration', function () {
    $playlist = $this->station->playlists()->create(['name' => 'Morning', 'playback_mode' => 'sequential']);
    $slot = $this->station->hourGridSlots()->create(['weekday' => 0, 'hour' => 10, 'playlist_id' => $playlist->id]);

    $trimmed = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'duration_seconds' => 200,
        'cue_in_seconds' => 10.0,
        'cue_out_seconds' => 170.0,
    ]);

    $playlist->items()->create(['position' => 0, 'type' => 'music', 'title' => $trimmed->title, 'media_file_id' => $trimmed->id]);

    $rundown = app(RundownGeneratorService::class)->generate($this->station, $slot, Carbon::parse('2026-05-12'));

    expect($rundown->items->first()->duration_seconds)->toBe(160);
});

test('a fill plans with the playback duration of its tracks', function () {
    foreach (range(1, 10) as $n) {
        MediaFile::factory()->create([
            'tenant_id' => $this->station->tenant_id,
            'artist' => "Artist {$n}",
            'album' => "Album {$n}",
            'duration_seconds' => 600,
            'cue_out_seconds' => 300.0,
        ]);
    }

    $playlist = $this->station->playlists()->create(['name' => 'Fill', 'playback_mode' => 'sequential']);
    $playlist->items()->create(['position' => 0, 'type' => 'fill', 'title' => 'Fill', 'fill_max_duration_seconds' => 1800]);
    $slot = $this->station->hourGridSlots()->create(['weekday' => 0, 'hour' => 10, 'playlist_id' => $playlist->id]);

    $rundown = app(RundownGeneratorService::class)->generate($this->station, $slot, Carbon::parse('2026-05-12'));

    // Six trimmed tracks fill the 30 minutes; with the raw length it would be three.
    expect($rundown->items)->toHaveCount(6)
        ->and($rundown->items->pluck('duration_seconds')->unique()->all())->toBe([300]);
});

test('the random element estimate averages the playback duration of the pool', function () {
    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'duration_seconds' => 200]);
    MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'duration_seconds' => 300,
        'cue_in_seconds' => 50.0,
        'cue_out_seconds' => 150.0,
    ]);

    $playlist = $this->station->playlists()->create(['name' => 'Random', 'playback_mode' => 'sequential']);
    $random = $playlist->items()->create(['position' => 0, 'type' => 'random', 'title' => 'Random']);

    // (200 + 100) / 2
    expect((new ElementLengths($this->station))->of($random))->toBe(150);
});

test('replacing a file archives its cue points and restoring brings them back', function () {
    Storage::fake('local');
    Queue::fake();

    $oldPath = "tenants/{$this->station->tenant_id}/media/old.mp3";
    $newPath = "tenants/{$this->station->tenant_id}/media/new.mp3";
    Storage::disk('local')->put($oldPath, 'old');
    Storage::disk('local')->put($newPath, 'new');

    $file = MediaFile::factory()->withCuePoints()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => $oldPath,
        'duration_seconds' => 200,
    ]);

    $service = app(MediaReplacementService::class);
    $version = $service->replace($file, $newPath, 'new.mp3', $this->user);

    expect($version)
        ->cue_in_seconds->toBe(5.0)
        ->cue_out_seconds->toBe(190.0)
        ->and($file->fresh())
        ->cue_in_seconds->toBeNull()
        ->cue_out_seconds->toBeNull()
        // Fades are measured from the cue points and still make sense for the new file.
        ->fade_in_seconds->toBe(1.5)
        ->fade_out_seconds->toBe(3.0);

    $service->restore($version, $this->user);

    expect($file->fresh())
        ->file_path->toBe($oldPath)
        ->cue_in_seconds->toBe(5.0)
        ->cue_out_seconds->toBe(190.0)
        ->and(MediaFileVersion::where('media_file_id', $file->id)->sole()->file_path)->toBe($newPath);
});
