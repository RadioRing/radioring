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

test('setLiveConnected(true) marks the station live and logs the start once', function () {
    $this->service->setLiveConnected($this->station, true);
    // Zweiter Connect (z. B. Reconnect-Flackern) darf nicht erneut loggen.
    $this->service->setLiveConnected($this->station, true);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->live_active)->toBeTrue()
        ->and($state->live_started_at)->not->toBeNull()
        ->and($state->now_playing_item_id)->toBeNull();

    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_LIVE_STARTED)->count())->toBe(1);
});

test('setLive clears the now-playing snapshot so the player stops ticking', function () {
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'now_playing_title' => 'Alter Track',
        'now_playing_started_at' => now()->subSeconds(30),
        'now_playing_duration_seconds' => 180,
    ]);

    $this->service->setLive($this->station, 'Live', 'Host');

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->live_active)->toBeTrue()
        ->and($state->now_playing_title)->toBeNull()
        ->and($state->now_playing_started_at)->toBeNull()
        ->and($state->now_playing_duration_seconds)->toBeNull();
});

test('setLiveConnected(true) clears the now-playing snapshot', function () {
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'now_playing_title' => 'Alter Track',
        'now_playing_started_at' => now()->subSeconds(10),
    ]);

    $this->service->setLiveConnected($this->station, true);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->now_playing_title)->toBeNull()
        ->and($state->now_playing_started_at)->toBeNull();
});

test('setLiveConnected(false) clears the live state and logs the stop once', function () {
    $this->service->setLiveConnected($this->station, true);
    $this->service->setLiveConnected($this->station, false);
    // Zweiter Disconnect ohne aktive Live-Sendung → kein weiterer Log.
    $this->service->setLiveConnected($this->station, false);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->live_active)->toBeFalse()
        ->and($state->live_title)->toBeNull();

    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_LIVE_STOPPED)->count())->toBe(1);
});

test('prepareSkip rewinds the cursor to the track after the one on air', function () {
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $items = collect(range(0, 5))->map(fn ($pos) => GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/f{$pos}.mp3", 'title' => "F{$pos}"])->id,
        'position' => $pos,
        'source_type' => 'template_item',
        'title' => "F{$pos}",
    ]));

    // File 1 (Position 0) läuft, Pull-Cursor durch prefetch schon auf 4.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 4,
        'now_playing_item_id' => $items[0]->id,
        'now_playing_started_at' => now(),
    ]);

    $this->service->prepareSkip($this->station);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    // Nächster Pull soll File 2 (Position 1) liefern, nicht File 5 (Position 4).
    expect($state->current_item_position)->toBe(1)
        ->and($state->current_rundown_id)->toBe($rundown->id);
});

test('setNowPlaying stores a denormalized snapshot of the track', function () {
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/s.mp3", 'title' => 'Snapshot Song', 'artist' => 'Snapshot Artist'])->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Snapshot Song',
        'duration_seconds' => 222,
    ]);

    $this->service->setNowPlaying($this->station, $item);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->now_playing_item_id)->toBe($item->id)
        ->and($state->now_playing_title)->toBe('Snapshot Song')
        ->and($state->now_playing_artist)->toBe('Snapshot Artist')
        ->and($state->now_playing_source_type)->toBe('template_item')
        ->and($state->now_playing_duration_seconds)->toBe(222)
        ->and($state->now_playing_started_at)->not->toBeNull();
});

test('pullNextItem does not pull a future rundown early across a schedule gap', function () {
    // Aktuelle Stunde lief leer aus (Cursor erschöpft).
    $current = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $current->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/cur.mp3", 'title' => 'Cur'])->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Cur',
    ]);

    // Nächster belegter Slot erst in 3 Stunden – darf NICHT vorgezogen werden.
    $future = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => (now()->hour + 3) % 24,
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $future->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/fut.mp3", 'title' => 'Fut'])->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Fut',
    ]);

    // Cursor steht hinter dem letzten Item des aktuellen Rundowns (erschöpft).
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $current->id,
        'current_item_position' => 1,
    ]);

    $item = $this->service->pullNextItem($this->station);

    // Kein Track → Silence; der Future-Rundown bleibt unangetastet.
    expect($item)->toBeNull();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($current->id);
});

test('pullNextItem advances to the next rundown once its hour has arrived', function () {
    // Vorheriger Rundown (zwei Stunden zurück), bereits erschöpft.
    $current = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => max(0, now()->hour - 2),
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $current->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/c2.mp3", 'title' => 'C2'])->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'C2',
    ]);

    // Folgerundown der aktuellen Stunde – seine Sendezeit ist erreicht.
    $next = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);

    $nextItem = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $next->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/n2.mp3", 'title' => 'N2'])->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'N2',
    ]);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $current->id,
        'current_item_position' => 1,
    ]);

    $item = $this->service->pullNextItem($this->station);

    expect($item->id)->toBe($nextItem->id);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($next->id);
});

test('prepareSkip after a regenerate rewinds to the start of the current rundown', function () {
    // Szenario: Rundown wurde neu generiert → now_playing-Item gelöscht/genullt,
    // der Pull-Cursor ist durch prefetch aber schon vorausgelaufen.
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    collect(range(0, 3))->each(fn ($pos) => GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/n{$pos}.mp3", 'title' => "N{$pos}"])->id,
        'position' => $pos,
        'source_type' => 'template_item',
        'title' => "N{$pos}",
    ]));

    // Cursor durch prefetch auf 3 vorgelaufen, now_playing-Item ist weg (null).
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 3,
        'now_playing_item_id' => null,
        'now_playing_title' => '36 Grad',
    ]);

    $this->service->prepareSkip($this->station);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    // Nächster Pull soll N0 (Position 0) liefern, nicht N3.
    expect($state->current_item_position)->toBe(0)
        ->and($state->current_rundown_id)->toBe($rundown->id);
});

test('prepareSkip does nothing without a now-playing track', function () {
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => null,
        'current_item_position' => 3,
        'now_playing_item_id' => null,
    ]);

    $this->service->prepareSkip($this->station);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_item_position)->toBe(3);
});
