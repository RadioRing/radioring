<?php

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\Playlist;
use App\Models\Station;
use App\Models\User;
use App\Services\LiquidsoapStateService;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create(['user_id' => User::factory()]);
    $this->playlist = $this->station->playlists()->create(['name' => 'Voicetracking', 'playback_mode' => 'sequential']);
    $this->slot = $this->station->hourGridSlots()->create(['weekday' => 0, 'hour' => 10, 'playlist_id' => $this->playlist->id]);
    $this->broadcastDate = Carbon::parse('2026-05-12'); // Monday
});

/**
 * Seeds the music pool, one artist per track.
 *
 * @param  list<int>  $lengths
 */
function seedMusicPool(Station $station, array $lengths): void
{
    foreach ($lengths as $i => $length) {
        $station->mediaFiles()->create([
            'title' => "Song {$i}",
            'artist' => "Artist {$i}",
            'type' => 'music',
            'file_path' => "tenants/test/media/song{$i}.mp3",
            'duration_seconds' => $length,
        ]);
    }
}

function addVoiceTrack(Playlist $playlist, int $position, int $duration): void
{
    $file = test()->station->mediaFiles()->create([
        'title' => "Voice {$position}",
        'type' => 'jingle',
        'file_path' => "tenants/test/media/voice{$position}.mp3",
        'duration_seconds' => $duration,
    ]);

    $playlist->items()->create([
        'position' => $position,
        'type' => 'jingle',
        'title' => $file->title,
        'media_file_id' => $file->id,
    ]);
}

function addMarker(Playlist $playlist, int $position, int $at, string $mode = 'soft'): void
{
    $playlist->items()->create([
        'position' => $position,
        'type' => 'marker',
        'title' => 'Fixzeit',
        'relative_offset_seconds' => $at,
        'fixed_mode' => $mode,
    ]);
}

function generateRundown(): GeneratedPlaylist
{
    return app(RundownGeneratorService::class)
        ->generate(test()->station, test()->slot, test()->broadcastDate)
        ->load('items');
}

function fillSeconds(GeneratedPlaylist $rundown): int
{
    return (int) $rundown->items->where('source_type', 'resolved_fill')->sum('duration_seconds');
}

// ── Generating ───────────────────────────────────────────────────────────────

test('a marker hands its fixed time to the element behind it and is not played itself', function () {
    addVoiceTrack($this->playlist, 0, 60);
    addMarker($this->playlist, 1, 900);
    $this->playlist->items()->create(['position' => 2, 'type' => 'adbreak', 'title' => 'Werbung']);

    $rundown = generateRundown();
    $adbreak = $rundown->items->firstWhere('source_type', 'adbreak');

    expect($rundown->items)->toHaveCount(2)
        ->and($adbreak->fixed_at->format('H:i:s'))->toBe('10:15:00')
        ->and($adbreak->fixed_mode)->toBe('soft')
        ->and($adbreak->position)->toBe(1);
});

test('a fill in front of a soft fixed time ends as close to it as it can', function () {
    seedMusicPool($this->station, array_fill(0, 10, 200));
    addVoiceTrack($this->playlist, 0, 60);
    $this->playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Fill']);
    addMarker($this->playlist, 2, 900);
    $this->playlist->items()->create(['position' => 3, 'type' => 'adbreak', 'title' => 'Werbung']);

    $rundown = generateRundown();
    $adbreak = $rundown->items->firstWhere('source_type', 'adbreak');

    // 840 s to fill: four tracks are 40 s short, five 160 s over.
    expect(fillSeconds($rundown))->toBe(800)
        ->and($adbreak->absolute_broadcast_at->format('H:i:s'))->toBe('10:14:20');
});

test('a fill in front of a hard fixed time never falls short of it', function () {
    seedMusicPool($this->station, array_fill(0, 10, 200));
    addVoiceTrack($this->playlist, 0, 60);
    $this->playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Fill']);
    addMarker($this->playlist, 2, 900, 'hard');
    addVoiceTrack($this->playlist, 3, 60);

    $rundown = generateRundown();
    $pinned = $rundown->items->firstWhere('title', 'Voice 3');

    // Hard must not fall short, so the fifth track runs into the cut.
    expect(fillSeconds($rundown))->toBe(1000)
        ->and($pinned->isHardFixed())->toBeTrue()
        ->and($pinned->absolute_broadcast_at->format('H:i:s'))->toBe('10:15:00');
});

test('the last tracks of a fill are backtimed to the fixed time', function (string $mode) {
    // Lengths in 5 s steps: some pair closes the gap exactly.
    seedMusicPool($this->station, range(150, 345, 5));
    addVoiceTrack($this->playlist, 0, 60);
    $this->playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Fill']);
    addMarker($this->playlist, 2, 900, $mode);
    addVoiceTrack($this->playlist, 3, 60);

    expect(fillSeconds(generateRundown()))->toBeGreaterThanOrEqual(840)->toBeLessThanOrEqual(845);
})->with(['soft', 'hard']);

test('a fill with nothing behind it runs up to the full hour', function () {
    seedMusicPool($this->station, range(150, 345, 5));
    addVoiceTrack($this->playlist, 0, 600);
    $this->playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Fill']);

    expect(fillSeconds(generateRundown()))->toBeGreaterThanOrEqual(3000)->toBeLessThanOrEqual(3005);
});

test('whatever else stands in front of the fixed time is taken off the fill budget', function () {
    seedMusicPool($this->station, array_fill(0, 10, 200));
    $this->playlist->items()->create(['position' => 0, 'type' => 'fill', 'title' => 'Fill']);
    addVoiceTrack($this->playlist, 1, 300);
    addMarker($this->playlist, 2, 900);
    $this->playlist->items()->create(['position' => 3, 'type' => 'adbreak', 'title' => 'Werbung']);

    // 900 - 300 = 600 s of fill.
    expect(fillSeconds(generateRundown()))->toBe(600);
});

test('a fill inside a container runs up to the fixed time behind the container', function () {
    seedMusicPool($this->station, array_fill(0, 10, 200));
    $container = $this->station->playlists()->create([
        'name' => 'Voice + Music', 'kind' => Playlist::KIND_CONTAINER, 'playback_mode' => 'sequential',
    ]);
    addVoiceTrack($container, 0, 60);
    $container->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Fill']);

    $this->playlist->items()->create([
        'position' => 0, 'type' => 'container', 'title' => $container->name, 'container_playlist_id' => $container->id,
    ]);
    addMarker($this->playlist, 1, 900, 'hard');
    $this->playlist->items()->create(['position' => 2, 'type' => 'adbreak', 'title' => 'Werbung']);

    expect(fillSeconds(generateRundown()))->toBe(1000);
});

test('a marker in front of a container pins its first item', function () {
    addVoiceTrack($this->playlist, 0, 100);
    $container = $this->station->playlists()->create([
        'name' => 'Block', 'kind' => Playlist::KIND_CONTAINER, 'playback_mode' => 'sequential',
    ]);
    addVoiceTrack($container, 0, 60);
    addVoiceTrack($container, 1, 60);
    addMarker($this->playlist, 1, 600);
    $this->playlist->items()->create([
        'position' => 2, 'type' => 'container', 'title' => $container->name, 'container_playlist_id' => $container->id,
    ]);

    $rundown = generateRundown();

    expect($rundown->items[1]->fixed_at->format('H:i:s'))->toBe('10:10:00')
        ->and($rundown->items[2]->fixed_at)->toBeNull();
});

test('a marker inside a container is ignored', function () {
    $container = $this->station->playlists()->create([
        'name' => 'Block', 'kind' => Playlist::KIND_CONTAINER, 'playback_mode' => 'sequential',
    ]);
    addMarker($container, 0, 600, 'hard');
    addVoiceTrack($container, 1, 60);
    $this->playlist->items()->create([
        'position' => 0, 'type' => 'container', 'title' => $container->name, 'container_playlist_id' => $container->id,
    ]);

    $rundown = generateRundown();

    expect($rundown->items)->toHaveCount(1)
        ->and($rundown->items[0]->fixed_at)->toBeNull();
});

test('an own maximum duration still caps a fill in front of a fixed time', function () {
    seedMusicPool($this->station, array_fill(0, 10, 200));
    $this->playlist->items()->create(['position' => 0, 'type' => 'fill', 'title' => 'Fill', 'fill_max_duration_seconds' => 300]);
    addMarker($this->playlist, 1, 900);
    $this->playlist->items()->create(['position' => 2, 'type' => 'adbreak', 'title' => 'Werbung']);

    expect(fillSeconds(generateRundown()))->toBe(400);
});

// ── Playout ──────────────────────────────────────────────────────────────────

/**
 * Rundown with a voice track, 200 s fill tracks and $tail.
 *
 * @param  list<array<string, mixed>>  $tail
 * @return array{0: GeneratedPlaylist, 1: Collection<int, GeneratedPlaylistItem>}
 */
function playoutRundown(int $fillTracks, array $tail, int $hour = 10): array
{
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => test()->station->id,
        'broadcast_date' => '2026-05-12',
        'broadcast_hour' => $hour,
        'status' => 'ready',
    ]);

    $rows = [['title' => 'Voice', 'source_type' => 'template_item', 'duration_seconds' => 120]];

    foreach ($fillTracks > 0 ? range(1, $fillTracks) : [] as $i) {
        $rows[] = ['title' => "Song {$i}", 'source_type' => 'resolved_fill', 'duration_seconds' => 200];
    }

    foreach ([...$rows, ...$tail] as $position => $row) {
        GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $rundown->id, 'position' => $position, ...$row]);
    }

    return [$rundown, $rundown->items()->get()];
}

function putOnAir(GeneratedPlaylist $rundown, GeneratedPlaylistItem $onAir, Carbon $startedAt, int $cursor): void
{
    LiquidsoapState::create([
        'station_id' => test()->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => $cursor,
        'now_playing_item_id' => $onAir->id,
        'now_playing_source_type' => $onAir->source_type,
        'now_playing_duration_seconds' => $onAir->duration_seconds,
        'now_playing_started_at' => $startedAt,
    ]);
}

function softBreakAt(string $time): array
{
    return ['title' => 'START_AD_BREAK', 'source_type' => 'adbreak', 'fixed_at' => "2026-05-12 {$time}", 'fixed_mode' => 'soft'];
}

test('the playout skips the rest of the fill once the soft fixed time is due', function () {
    $this->travelTo(Carbon::parse('2026-05-12 10:14:00'));
    [$rundown, $items] = playoutRundown(3, [softBreakAt('10:15:00')]);
    // Voice ends at 10:15, no fill may start.
    putOnAir($rundown, $items[0], Carbon::parse('2026-05-12 10:13:00'), 1);

    $pulled = app(LiquidsoapStateService::class)->pullNextItem($this->station);

    expect($pulled->source_type)->toBe('adbreak')
        ->and(LiquidsoapState::first()->current_item_position)->toBe(5)
        ->and(GeneratedPlaylistItem::whereNotNull('skipped_at')->pluck('position')->all())->toBe([1, 2, 3]);
});

test('the playout keeps the fill while there is time left before the fixed time', function () {
    $this->travelTo(Carbon::parse('2026-05-12 10:10:00'));
    [$rundown, $items] = playoutRundown(3, [softBreakAt('10:15:00')]);
    putOnAir($rundown, $items[0], Carbon::parse('2026-05-12 10:10:00'), 1);

    $pulled = app(LiquidsoapStateService::class)->pullNextItem($this->station);

    expect($pulled->id)->toBe($items[1]->id)
        ->and(GeneratedPlaylistItem::whereNotNull('skipped_at')->count())->toBe(0);
});

test('the playout counts the prefetched tracks when it checks the fixed time', function () {
    $this->travelTo(Carbon::parse('2026-05-12 10:10:00'));
    [$rundown, $items] = playoutRundown(3, [softBreakAt('10:15:00')]);
    // Queued fill 1 runs until 10:15:20.
    putOnAir($rundown, $items[0], Carbon::parse('2026-05-12 10:10:00'), 2);

    $pulled = app(LiquidsoapStateService::class)->pullNextItem($this->station);

    expect($pulled->source_type)->toBe('adbreak')
        ->and(GeneratedPlaylistItem::whereNotNull('skipped_at')->pluck('position')->all())->toBe([2, 3]);
});

test('the playout never skips what stands between the fill and the fixed time', function () {
    $this->travelTo(Carbon::parse('2026-05-12 10:12:00'));
    [$rundown, $items] = playoutRundown(2, [
        ['title' => 'Voice 2', 'source_type' => 'template_item', 'duration_seconds' => 120],
        softBreakAt('10:15:00'),
    ]);
    // Fill plus the 2 min voice would reach past 10:15: fill skipped, voice kept.
    putOnAir($rundown, $items[0], Carbon::parse('2026-05-12 10:12:00'), 1);

    $pulled = app(LiquidsoapStateService::class)->pullNextItem($this->station);

    expect($pulled->title)->toBe('Voice 2')
        ->and(GeneratedPlaylistItem::whereNotNull('skipped_at')->pluck('position')->all())->toBe([1, 2]);
});

test('a soft 00:00 of the next hour drops the fill at the end of this one', function () {
    $this->travelTo(Carbon::parse('2026-05-12 10:59:00'));
    [$rundown, $items] = playoutRundown(2, []);
    [, $next] = playoutRundown(0, [], hour: 11);
    $next[0]->update(['fixed_at' => '2026-05-12 11:00:00', 'fixed_mode' => 'soft']);

    // Voice runs until 11:00:10, no room for this hour's fill.
    putOnAir($rundown, $items[0], Carbon::parse('2026-05-12 10:58:10'), 1);

    $pulled = app(LiquidsoapStateService::class)->pullNextItem($this->station);

    expect($pulled->id)->toBe($next[0]->id)
        ->and(GeneratedPlaylistItem::whereNotNull('skipped_at')->pluck('id')->all())->toBe([$items[1]->id, $items[2]->id]);
});

test('an item with a hard fixed time is not handed out before its time', function () {
    $this->travelTo(Carbon::parse('2026-05-12 10:14:00'));
    [$rundown, $items] = playoutRundown(0, [
        ['title' => 'News', 'source_type' => 'template_item', 'duration_seconds' => 120, 'fixed_at' => '2026-05-12 10:15:00', 'fixed_mode' => 'hard'],
    ]);
    putOnAir($rundown, $items[0], Carbon::parse('2026-05-12 10:13:00'), 1);

    $service = app(LiquidsoapStateService::class);

    // Held back until 10:15.
    expect($service->pullNextItem($this->station))->toBeNull()
        ->and(LiquidsoapState::first()->current_item_position)->toBe(1);

    $this->travelTo(Carbon::parse('2026-05-12 10:15:00'));

    expect($service->pullNextItem($this->station)->title)->toBe('News');
});

test('a hard fixed time in the middle of the hour is announced and cut to', function () {
    $this->travelTo(Carbon::parse('2026-05-12 10:29:00'));
    [$rundown, $items] = playoutRundown(3, [
        ['title' => 'News', 'source_type' => 'template_item', 'duration_seconds' => 120, 'fixed_at' => '2026-05-12 10:30:00', 'fixed_mode' => 'hard'],
    ]);
    // Voice ends at 10:29:30, fill 1 still starts.
    putOnAir($rundown, $items[0], Carbon::parse('2026-05-12 10:27:30'), 1);

    $service = app(LiquidsoapStateService::class);
    $news = $items[4];

    expect($service->upcomingHardStart($this->station)?->id)->toBe($news->id)
        ->and($service->secondsUntilStart($news))->toBe(60.0);

    $service->announceHardStart($this->station, $news);

    // Before the cut: fill plays, news not queued.
    expect($service->pullNextItem($this->station)->id)->toBe($items[1]->id);

    // After the cut: news.
    $this->travelTo(Carbon::parse('2026-05-12 10:30:00'));
    expect($service->pullNextItem($this->station)->id)->toBe($news->id);
});
