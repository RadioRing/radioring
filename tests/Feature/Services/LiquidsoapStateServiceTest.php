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

test('pullNextItem opens an underrun and logs it once the gap exceeds the threshold', function () {
    config(['radioring.underrun_alert_seconds' => 30]);

    // Aktuelle Stunde ist erschöpft, der Folge-Rundown ist noch nicht freigegeben.
    $current = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $current->id,
        'current_item_position' => 0,
    ]);

    expect($this->service->pullNextItem($this->station))->toBeNull();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->underrun_started_at)->not->toBeNull()
        ->and($state->underrun_logged_at)->toBeNull();

    $openedAt = $state->underrun_started_at;

    // Innerhalb der Schwelle bleibt es still – eine Sekunde Lücke ist kein Vorfall.
    $this->travel(20)->seconds();
    expect($this->service->pullNextItem($this->station))->toBeNull();
    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_UNDERRUN)->count())->toBe(0);

    // Jenseits der Schwelle: genau ein Protokolleintrag, egal wie oft weiter gezogen wird.
    $this->travel(20)->seconds();
    expect($this->service->pullNextItem($this->station))->toBeNull();
    expect($this->service->pullNextItem($this->station))->toBeNull();

    $logs = StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_UNDERRUN)->get();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->generated_playlist_id)->toBe($current->id);

    // Der Beginn wandert nicht mit: die Lücke wird ab dem ersten leeren Pull gemessen.
    $state->refresh();
    expect($state->underrun_started_at->timestamp)->toBe($openedAt->timestamp)
        ->and($state->underrun_logged_at)->not->toBeNull();
});

test('pullNextItem closes the underrun as soon as an item is available again', function () {
    config(['radioring.underrun_alert_seconds' => 30]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/back.mp3", 'title' => 'Back'])->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Back',
    ]);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 0,
        'underrun_started_at' => now()->subMinutes(10),
        'underrun_logged_at' => now()->subMinutes(9),
    ]);

    expect($this->service->pullNextItem($this->station)->id)->toBe($item->id);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->underrun_started_at)->toBeNull()
        ->and($state->underrun_logged_at)->toBeNull();
});

test('an announced hard start still serves the running hour until the cut', function () {
    $running = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 12,
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);

    $runningItem = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $running->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/r.mp3", 'title' => 'R'])->id,
        'position' => 3,
        'source_type' => 'template_item',
        'title' => 'R3',
    ]);

    $hard = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 13,
        'status' => 'ready',
        'start_mode' => 'hard',
    ]);

    $news = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $hard->id,
        'media_file_id' => null,
        'position' => 0,
        'source_type' => 'news_weather',
        'title' => 'Nachrichten',
    ]);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $running->id,
        'current_item_position' => 3,
    ]);

    $this->travelTo(today()->setTime(12, 59, 0));
    $this->service->announceHardStart($this->station, $hard);

    // Vor dem Schnitt liefert der Pull weiter die laufende Stunde - der Prefetch darf die
    // Nachrichten nicht vorziehen, sonst wirft das set_queue([]) des Cuts sie weg.
    expect($this->service->pullNextItem($this->station)->id)->toBe($runningItem->id);

    // Nach dem Schnitt landet der erste Pull auf Position 0 des Hard-Rundowns, ohne dass
    // jemand den Cursor umgesetzt hat: das erledigt der Hard-Start-Zweig beim Aufloesen.
    $this->travelTo(today()->setTime(13, 0, 1));
    expect($this->service->pullNextItem($this->station)->id)->toBe($news->id);
});

test('upcomingHardStart announces only within its lead window', function () {
    $hard = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 13,
        'status' => 'ready',
        'start_mode' => 'hard',
    ]);

    // Zu frueh: der Lauf um 12:58 sieht die Stunde noch nicht.
    $this->travelTo(today()->setTime(12, 58, 0));
    expect($this->service->upcomingHardStart($this->station))->toBeNull();

    // Im Fenster: 60 Sekunden Vorlauf.
    $this->travelTo(today()->setTime(12, 59, 0));
    expect($this->service->upcomingHardStart($this->station)?->id)->toBe($hard->id);
    expect($this->service->secondsUntilStart($hard))->toBe(60.0);

    // Ab der vollen Stunde uebernimmt pendingHardStart und schneidet sofort.
    $this->travelTo(today()->setTime(13, 0, 0));
    expect($this->service->upcomingHardStart($this->station))->toBeNull();
});

test('a dry pull raises no underrun while a track is still audibly running', function () {
    config(['radioring.underrun_alert_seconds' => 30]);

    // Der Rundown ist am Cursor erschoepft, hoerbar laeuft aber noch ein Track aus dem
    // Prefetch-Puffer. Genau hier schlug die Warnung frueher an, obwohl die Station sendete.
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);

    $state = LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 0,
        'now_playing_title' => 'Laufender Song',
        'now_playing_source_type' => 'music',
        'now_playing_duration_seconds' => 180,
        'now_playing_started_at' => now(),
    ]);

    $this->travel(90)->seconds();

    expect($this->service->pullNextItem($this->station))->toBeNull();

    $state->refresh();

    // Der Zeitpunkt wird gemerkt, gemeldet wird nichts.
    expect($state->underrun_started_at)->not->toBeNull()
        ->and($state->underrunSeconds())->toBeNull()
        ->and($state->underrun_logged_at)->toBeNull();

    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_UNDERRUN)->count())->toBe(0);

    // Erst wenn der Track abgelaufen ist und nichts nachkommt, ist es ein Underrun - und
    // gezaehlt wird ab dem Ende des Tracks, nicht ab dem ersten trockenen Pull.
    $this->travel(240)->seconds();

    expect($this->service->pullNextItem($this->station))->toBeNull();

    $state->refresh();
    expect($state->underrunSeconds())->toBe(150)
        ->and($state->underrun_logged_at)->not->toBeNull();

    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_UNDERRUN)->count())->toBe(1);
});

test('a track going on air closes an open underrun episode', function () {
    config(['radioring.underrun_alert_seconds' => 30]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/on.mp3", 'title' => 'On'])->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'On',
        'duration_seconds' => 180,
    ]);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 1,
        'underrun_started_at' => now()->subMinutes(10),
        'underrun_logged_at' => now()->subMinutes(9),
    ]);

    // Ein Track aus dem Prefetch-Puffer geht auf Sendung: die Station ist hoerbar da.
    $this->service->setNowPlaying($this->station, $item);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->underrun_started_at)->toBeNull()
        ->and($state->underrun_logged_at)->toBeNull()
        ->and($state->underrunSeconds())->toBeNull();
});

test('a live takeover is never reported as an underrun', function () {
    config(['radioring.underrun_alert_seconds' => 30]);

    $state = LiquidsoapState::create([
        'station_id' => $this->station->id,
        'live_active' => true,
        'underrun_started_at' => now()->subMinutes(10),
    ]);

    // Waehrend einer Uebernahme liefert /next nichts, gesendet wird trotzdem.
    expect($state->underrunSeconds())->toBeNull();
});
