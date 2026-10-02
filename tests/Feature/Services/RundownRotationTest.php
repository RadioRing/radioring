<?php

use App\Models\Station;
use App\Models\StationLog;
use App\Models\User;
use App\Services\MusicRotationPlanner;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->service = app(RundownGeneratorService::class);
    $this->date = Carbon::parse('2026-05-12'); // Montag
});

/**
 * Erstellt einen Slot mit einer Fill-Playlist für eine bestimmte Stunde.
 */
function fillSlot(Station $station, int $hour, int $maxDuration = 3600, ?array $tagIds = null)
{
    $playlist = $station->playlists()->create([
        'name' => "Fill {$hour}",
        'playback_mode' => 'sequential',
    ]);

    $playlist->items()->create([
        'position' => 0,
        'type' => 'fill',
        'title' => 'Auffüllen',
        'fill_max_duration_seconds' => $maxDuration,
        'fill_tags' => $tagIds,
    ]);

    return $station->hourGridSlots()->create([
        'weekday' => 0,
        'hour' => $hour,
        'playlist_id' => $playlist->id,
    ]);
}

test('a generated fill never repeats the same artist more than three times in a row', function () {
    // Viel Abwechslung: 6 Künstler à 3 Tracks, je 10 min.
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $artist) {
        foreach (range(1, 3) as $n) {
            $this->station->mediaFiles()->create([
                'title' => "{$artist}{$n}",
                'artist' => $artist,
                'album' => "{$artist}-Album-{$n}",
                'type' => 'music',
                'file_path' => "tenants/{$this->station->tenant_id}/media/{$artist}{$n}.mp3",
                'duration_seconds' => 600,
            ]);
        }
    }

    $slot = fillSlot($this->station, 10);
    $rundown = $this->service->generate($this->station, $slot, $this->date);

    $artists = $rundown->items()->with('mediaFile')->get()
        ->map(fn ($i) => $i->mediaFile?->artist)
        ->all();

    $maxRun = 0;
    $run = 0;
    $prev = null;
    foreach ($artists as $a) {
        $run = ($a === $prev) ? $run + 1 : 1;
        $prev = $a;
        $maxRun = max($maxRun, $run);
    }

    expect($maxRun)->toBeLessThanOrEqual(MusicRotationPlanner::MAX_ARTIST_IN_A_ROW);
});

test('the previous hour rundown influences the next hour selection', function () {
    $tag = $this->station->tags()->create(['name' => 'rot']);

    // Stunde 10: Playlist mit drei festen Tracks von Artist X am Stück.
    $playlist10 = $this->station->playlists()->create(['name' => 'Zehn', 'playback_mode' => 'sequential']);
    foreach (range(1, 3) as $n) {
        $file = $this->station->mediaFiles()->create([
            'title' => "X{$n}",
            'artist' => 'X',
            'album' => "X-Album-{$n}",
            'type' => 'music',
            'file_path' => "tenants/{$this->station->tenant_id}/media/x{$n}.mp3",
            'duration_seconds' => 1200, // 20 min → 10:00, 10:20, 10:40
        ]);
        $playlist10->items()->create([
            'position' => $n - 1,
            'type' => 'music',
            'title' => "X{$n}",
            'media_file_id' => $file->id,
        ]);
    }
    $slot10 = $this->station->hourGridSlots()->create([
        'weekday' => 0, 'hour' => 10, 'playlist_id' => $playlist10->id,
    ]);

    // Stunde 11: Fill nur aus zwei getaggten Tracks – ein weiterer X und ein Y.
    $xFill = $this->station->mediaFiles()->create([
        'title' => 'X-Fill', 'artist' => 'X', 'album' => 'X-Fill-Album',
        'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/xfill.mp3",
        'duration_seconds' => 600,
    ]);
    $yFill = $this->station->mediaFiles()->create([
        'title' => 'Y-Fill', 'artist' => 'Y', 'album' => 'Y-Fill-Album',
        'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/yfill.mp3",
        'duration_seconds' => 600,
    ]);
    $xFill->tags()->attach($tag);
    $yFill->tags()->attach($tag);

    $slot11 = fillSlot($this->station, 11, maxDuration: 600, tagIds: [$tag->id]);

    // Reihenfolge wie im Tagesjob: erst Stunde 10, dann 11.
    $this->service->generate($this->station, $slot10, $this->date);
    $rundown11 = $this->service->generate($this->station, $slot11, $this->date);

    // Stunde 10 endete mit 3× X am Stück → der erste Fill-Track in Stunde 11 darf kein X sein.
    $firstFill = $rundown11->items()->with('mediaFile')->first();

    expect($firstFill->mediaFile->artist)->toBe('Y');
});

test('regenerating an hour takes the already planned following hour into account', function () {
    $tag = $this->station->tags()->create(['name' => 'rot']);

    // Hour 11 opens with a fixed X title.
    $playlist11 = $this->station->playlists()->create(['name' => 'Elf', 'playback_mode' => 'sequential']);
    $opener = $this->station->mediaFiles()->create([
        'title' => 'X-Opener', 'artist' => 'X', 'album' => 'X-Opener-Album',
        'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/xopener.mp3",
        'duration_seconds' => 600,
    ]);
    $playlist11->items()->create([
        'position' => 0, 'type' => 'music', 'title' => 'X-Opener', 'media_file_id' => $opener->id,
    ]);
    $slot11 = $this->station->hourGridSlots()->create([
        'weekday' => 0, 'hour' => 11, 'playlist_id' => $playlist11->id,
    ]);

    // Hour 10 is filled from one X and one Y title of 30 minutes each.
    foreach (['X', 'Y'] as $artist) {
        $file = $this->station->mediaFiles()->create([
            'title' => "{$artist}-Long", 'artist' => $artist, 'album' => "{$artist}-Long-Album",
            'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/{$artist}long.mp3",
            'duration_seconds' => 1800,
        ]);
        $file->tags()->attach($tag);
    }
    $slot10 = fillSlot($this->station, 10, tagIds: [$tag->id]);

    // Hour 11 exists first, as when a single earlier hour is regenerated.
    $this->service->generate($this->station, $slot11, $this->date);

    foreach (range(1, 5) as $run) {
        $rundown10 = $this->service->generate($this->station, $slot10, $this->date, force: true);

        // The last title of hour 10 runs straight into the X opener of hour 11.
        $last = $rundown10->items()->with('mediaFile')->get()->last();

        expect($last->mediaFile->artist)->toBe('Y');
    }
});

test('a rundown that cannot keep the GVL rules leaves a note in the protocol', function () {
    // A single artist cannot fill an hour within the GVL limits.
    foreach (range(1, 12) as $n) {
        $this->station->mediaFiles()->create([
            'title' => "Solo{$n}", 'artist' => 'Solo', 'album' => "Solo-Album-{$n}",
            'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/solo{$n}.mp3",
            'duration_seconds' => 300,
        ]);
    }

    $rundown = $this->service->generate($this->station, fillSlot($this->station, 10), $this->date);

    $log = StationLog::where('event', StationLog::EVENT_ROTATION_VIOLATION)->sole();

    expect($log->station_id)->toBe($this->station->id)
        ->and($log->generated_playlist_id)->toBe($rundown->id)
        ->and($log->message)->toContain('GVL');
});

test('a varied pool leaves no rotation note in the protocol', function () {
    foreach (range(1, 20) as $n) {
        $this->station->mediaFiles()->create([
            'title' => "T{$n}", 'artist' => "Artist{$n}", 'album' => "Album{$n}",
            'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/t{$n}.mp3",
            'duration_seconds' => 300,
        ]);
    }

    $this->service->generate($this->station, fillSlot($this->station, 10), $this->date);

    expect(StationLog::where('event', StationLog::EVENT_ROTATION_VIOLATION)->exists())->toBeFalse();
});
