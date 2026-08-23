<?php

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\StationLog;
use App\Models\User;
use App\Services\LiquidsoapStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create(['user_id' => User::factory()]);
    $this->service = app(LiquidsoapStateService::class);
});

function logPlaylistItem(Station $station, string $title): GeneratedPlaylistItem
{
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    return GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => MediaFile::factory()->create([
            'tenant_id' => $station->tenant_id,
            'type' => 'music',
            'title' => $title,
            'artist' => 'Some Artist',
        ])->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => $title,
    ]);
}

test('setNowPlaying writes a playlist track entry', function () {
    $item = logPlaylistItem($this->station, 'Song A');

    $this->service->setNowPlaying($this->station, $item);

    $log = StationLog::where('station_id', $this->station->id)->sole();
    expect($log->event)->toBe(StationLog::EVENT_TRACK)
        ->and($log->source)->toBe('playlist')
        ->and($log->title)->toBe('Song A')
        ->and($log->artist)->toBe('Some Artist');
});

test('a duplicate report of the same item is logged only once', function () {
    // Container meldet denselben Track erneut (z. B. nach Wieder-Anlaufen) → nur ein Eintrag.
    $item = logPlaylistItem($this->station, 'Song A');

    $this->service->setNowPlaying($this->station, $item);
    $this->service->setNowPlaying($this->station, $item);

    expect(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_TRACK)->count())->toBe(1);
});

test('a duplicate report does not reset the play start time', function () {
    $item = logPlaylistItem($this->station, 'Song A');

    $this->service->setNowPlaying($this->station, $item);
    $startedAt = LiquidsoapState::where('station_id', $this->station->id)->first()->now_playing_started_at;

    $this->travel(30)->seconds();
    $this->service->setNowPlaying($this->station, $item);

    expect(LiquidsoapState::where('station_id', $this->station->id)->first()->now_playing_started_at->timestamp)
        ->toBe($startedAt->timestamp);
});

test('a genuine repeat (different item, same title) stays visible', function () {
    // Echte Titel-Wiederholung ist ein eigenes Rundown-Item (andere ID) → wird geloggt.
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $first = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'Song A',
    ]);
    $second = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id, 'position' => 1, 'source_type' => 'template_item', 'title' => 'Song A',
    ]);

    $this->service->setNowPlaying($this->station, $first);
    $this->service->setNowPlaying($this->station, $second);

    expect(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_TRACK)->count())->toBe(2);
});

test('silence (no item) does not write a track entry', function () {
    $this->service->setNowPlaying($this->station, null);

    expect(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_TRACK)->count())->toBe(0);
});

test('setLive writes a live track entry and a live-started event', function () {
    $this->service->setLive($this->station, 'Live Title', 'Live Host');

    expect(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_LIVE_STARTED)->count())->toBe(1)
        ->and(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_TRACK)->where('source', 'live')->count())->toBe(1);
});

test('live-started is logged only once across repeated live updates', function () {
    $this->service->setLive($this->station, 'Live Title', 'Live Host');
    $this->service->setLive($this->station, 'Next Track', 'Live Host');

    expect(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_LIVE_STARTED)->count())->toBe(1);
});

test('repeated identical live metadata is logged only once as a track', function () {
    $this->service->setLive($this->station, 'Live Title', 'Live Host');
    $this->service->setLive($this->station, 'Live Title', 'Live Host');

    expect(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_TRACK)->count())->toBe(1);
});

test('switching from live back to playlist logs a live-stopped event', function () {
    $this->service->setLive($this->station, 'Live Title', 'Live Host');

    $item = logPlaylistItem($this->station, 'Song A');
    $this->service->setNowPlaying($this->station, $item);

    expect(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_LIVE_STOPPED)->count())->toBe(1);
});

test('a playlist track without a preceding live takeover logs no live-stopped event', function () {
    $item = logPlaylistItem($this->station, 'Song A');
    $this->service->setNowPlaying($this->station, $item);

    expect(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_LIVE_STOPPED)->count())->toBe(0);
});
