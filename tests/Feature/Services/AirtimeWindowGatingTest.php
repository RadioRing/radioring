<?php

use App\Models\HourGridSlot;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->service = app(RundownGeneratorService::class);
    $this->monday = Carbon::parse('2026-05-11'); // Monday
});

/**
 * A slot whose playlist holds a single random element, optionally narrowed to a tag.
 *
 * @param  array<int, int>  $tagIds
 */
function slotWithRandomItem(Station $station, int $hour, array $tagIds = []): HourGridSlot
{
    $playlist = $station->playlists()->create([
        'name' => 'Random Playlist',
        'playback_mode' => 'sequential',
    ]);

    $playlist->items()->create([
        'position' => 0,
        'type' => 'random',
        'title' => 'Zufall',
        'fill_tags' => $tagIds,
    ]);

    return $station->hourGridSlots()->create([
        'weekday' => 1,
        'hour' => $hour,
        'playlist_id' => $playlist->id,
    ]);
}

test('a random element picks a jingle inside its airtime window', function () {
    $jingle = $this->station->mediaFiles()->create([
        'title' => 'Guten Morgen',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/morgen.mp3',
        'duration_seconds' => 10,
        'airtime_windows' => [['days' => [], 'from' => '06:00', 'to' => '10:00']],
    ]);

    $rundown = $this->service->generate($this->station, slotWithRandomItem($this->station, 7), $this->monday);

    expect($rundown->items)->toHaveCount(1)
        ->and($rundown->items->first()->media_file_id)->toBe($jingle->id);
});

test('a random element skips a jingle outside its airtime window', function () {
    $this->station->mediaFiles()->create([
        'title' => 'Guten Morgen',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/morgen.mp3',
        'duration_seconds' => 10,
        'airtime_windows' => [['days' => [], 'from' => '06:00', 'to' => '10:00']],
    ]);

    $rundown = $this->service->generate($this->station, slotWithRandomItem($this->station, 14), $this->monday);

    expect($rundown->items)->toHaveCount(0);
});

test('a random element still finds the ungated files of the same pool', function () {
    $gated = $this->station->mediaFiles()->create([
        'title' => 'Guten Morgen',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/morgen.mp3',
        'duration_seconds' => 10,
        'airtime_windows' => [['days' => [], 'from' => '06:00', 'to' => '10:00']],
    ]);
    $ungated = $this->station->mediaFiles()->create([
        'title' => 'Immer',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/immer.mp3',
        'duration_seconds' => 10,
    ]);

    $rundown = $this->service->generate($this->station, slotWithRandomItem($this->station, 14), $this->monday);

    expect($rundown->items)->toHaveCount(1)
        ->and($rundown->items->first()->media_file_id)->toBe($ungated->id)
        ->and($rundown->items->first()->media_file_id)->not->toBe($gated->id);
});

test('a fill element leaves out music that may not air at that hour', function () {
    $this->station->mediaFiles()->create([
        'title' => 'Nur nachts',
        'type' => 'music',
        'file_path' => 'tenants/test/media/nacht.mp3',
        'duration_seconds' => 200,
        'airtime_windows' => [['days' => [], 'from' => '22:00', 'to' => '02:00']],
    ]);
    $daytime = $this->station->mediaFiles()->create([
        'title' => 'Immer',
        'type' => 'music',
        'file_path' => 'tenants/test/media/immer.mp3',
        'duration_seconds' => 200,
    ]);

    $playlist = $this->station->playlists()->create([
        'name' => 'Fill Playlist',
        'playback_mode' => 'sequential',
    ]);
    $playlist->items()->create([
        'position' => 0,
        'type' => 'fill',
        'title' => 'Musik',
        'fill_max_duration_seconds' => 600,
    ]);
    $slot = $this->station->hourGridSlots()->create([
        'weekday' => 1,
        'hour' => 14,
        'playlist_id' => $playlist->id,
    ]);

    $rundown = $this->service->generate($this->station, $slot, $this->monday);

    expect($rundown->items)->not->toBeEmpty()
        ->and($rundown->items->pluck('media_file_id')->unique()->all())->toBe([$daytime->id]);
});
