<?php

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->token = $this->station->api_token;
});

// ── /api/liquidsoap/{slug}/next ─────────────────────────────────────────────

test('next rejects missing token', function () {
    $this->getJson("/api/liquidsoap/{$this->station->slug}/next")
        ->assertStatus(401);
});

test('next rejects wrong token', function () {
    $this->withToken('wrong-token')
        ->getJson("/api/liquidsoap/{$this->station->slug}/next")
        ->assertStatus(401);
});

test('next returns empty when no rundown exists', function () {
    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())->toBe('');
});

test('next returns media url for first item in ready rundown', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3",
        'title' => 'Test Song',
        'type' => 'music',
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Test Song',
        'duration_seconds' => 180,
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    // Signierte URL statt api_token im Query-String.
    expect($response->getContent())
        ->toContain('/api/stream/media/'.$this->station->slug.'/'.$file->id)
        ->toContain('signature=')
        ->toContain('expires=')
        ->not->toContain('token='.$this->token);
});

test('next annotates the track with its database item id', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3",
        'type' => 'music',
        'title' => 'Test Song',
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Test Song',
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())
        ->toStartWith('annotate:radioring_item_id="'.$item->id.'"')
        ->toContain('/api/stream/media/'.$this->station->slug.'/'.$file->id)
        // Die URL wird über das crash-sichere "safe:"-Protokoll aufgelöst, damit ein
        // CurlException beim Download nicht die Liquidsoap-Engine killt.
        ->toContain(':safe:')
        // Basis ist LIQUIDSOAP_API_URL, also dieselbe Adresse, unter der der Container
        // auch /next abruft - nicht mehr zwingend APP_URL.
        ->toContain(':safe:'.rtrim(config('radioring.liquidsoap_api_url') ?: config('app.url'), '/').'/api/stream/media/');
});

test('next annotates the measured loudness gain as liq_amplify', function () {
    Storage::fake('local');

    // Gemessen mit -20 LUFS, True-Peak -8 dBTP → Ziel -14 ⇒ +6 dB (Peak-Cap nicht aktiv).
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3",
        'type' => 'music',
        'title' => 'Quiet Song',
        'loudness_lufs' => -20.0,
        'loudness_true_peak' => -8.0,
        'loudness_measured_at' => now(),
    ]);

    config(['radioring.loudness.enabled' => true, 'radioring.loudness.target_lufs' => -14.0]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Quiet Song',
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())->toContain('liq_amplify="6 dB"');
});

test('next caps the loudness gain so the true peak stays below clipping', function () {
    Storage::fake('local');

    // Leise (-30 LUFS) aber peakig (-2 dBTP): voller Gain wäre +16 dB, aber der True-Peak
    // läge dann bei +14 dBTP. Cap auf max -1 dBTP ⇒ Gain = -1 - (-2) = +1 dB.
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/peaky.mp3",
        'type' => 'music',
        'title' => 'Peaky',
        'loudness_lufs' => -30.0,
        'loudness_true_peak' => -2.0,
        'loudness_measured_at' => now(),
    ]);

    config(['radioring.loudness.enabled' => true, 'radioring.loudness.target_lufs' => -14.0, 'radioring.loudness.max_true_peak_dbtp' => -1.0]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Peaky',
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())->toContain('liq_amplify="1 dB"');
});

test('next omits liq_amplify for an unmeasured track', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/raw.mp3",
        'type' => 'music',
        'title' => 'Unmeasured',
        'loudness_lufs' => null,
    ]);

    config(['radioring.loudness.enabled' => true]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Unmeasured',
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())->not->toContain('liq_amplify');
});

test('next annotates liq_fade_in for a track with fade-in enabled', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/jingle.mp3",
        'type' => 'jingle',
        'title' => 'Jingle',
        'fade_in' => true,
    ]);

    config(['radioring.fade_in_seconds' => 2.0]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Jingle',
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())->toContain('liq_fade_in="2"');
});

test('next omits liq_fade_in for a track without fade-in', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3",
        'type' => 'music',
        'title' => 'Song',
        'fade_in' => false,
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Song',
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())->not->toContain('liq_fade_in');
});

test('next annotates title and artist from the database, not the id3 tags', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3",
        'type' => 'music',
        'title' => 'Korrigierter Titel',
        'artist' => 'Korrigierter Interpret',
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    // Rundown-Snapshot trägt einen ALTEN Titel – die DB-Korrektur (mediaFile) soll gewinnen.
    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Alter Snapshot-Titel',
    ]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())
        ->toContain('title="Korrigierter Titel"')
        ->toContain('artist="Korrigierter Interpret"')
        ->not->toContain('Alter Snapshot-Titel');
});

test('next neutralizes quotes in title and artist so the annotate string stays valid', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3",
        'type' => 'music',
        'title' => 'Tina S. "Live"',
        'artist' => 'A "B" C',
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'x',
    ]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    // Doppelte Anführungszeichen im Wert wurden zu einfachen – der annotate-Parser bleibt intakt.
    expect($response->getContent())
        ->toContain("title=\"Tina S. 'Live'\"")
        ->toContain("artist=\"A 'B' C\"");
});

test('next returns the adbreak signal with the configured path and item id', function () {
    config(['radioring.adbreak_signal_path' => '/opt/ad_break.mp3']);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'position' => 0,
        'source_type' => 'adbreak',
        'title' => 'START_AD_BREAK',
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())
        ->toContain('radioring_item_id="'.$item->id.'"')
        ->toContain('title="START_AD_BREAK"')
        ->toContain(':/opt/ad_break.mp3');
});

test('next returns the laut.fm news url built from the station output credentials', function (string $sourceType, int $segment) {
    $this->station->outputs()->create([
        'type' => 'lautfm',
        'host' => 'stream.laut.fm',
        'port' => 80,
        'mount' => '/teststation',
        'username' => 'teststation',
        'password' => 'geheim123',
        'bitrate' => 128,
        'enabled' => true,
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'position' => 0,
        'source_type' => $sourceType,
        'title' => 'laut.fm Block',
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())
        ->toContain('radioring_item_id="'.$item->id.'"')
        ->toContain("https://teststation:geheim123@api.radioadmin.laut.fm/news/{$segment}");
})->with([
    ['news_weather', 1],
    ['news', 2],
    ['weather', 3],
]);

test('next skips news items when station has no laut.fm output', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3",
        'type' => 'music',
        'title' => 'Fallback Song',
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'position' => 0,
        'source_type' => 'news',
        'title' => 'Nachrichten',
    ]);
    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 1,
        'source_type' => 'template_item',
        'title' => 'Fallback Song',
    ]);

    $response = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())->toContain('/api/stream/media/'.$this->station->slug.'/'.$file->id);
});

test('next advances position on each call', function () {
    Storage::fake('local');

    $file1 = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/song1.mp3", 'type' => 'music', 'title' => 'Song 1']);
    $file2 = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/song2.mp3", 'type' => 'music', 'title' => 'Song 2']);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $rundown->id, 'media_file_id' => $file1->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'Song 1']);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $rundown->id, 'media_file_id' => $file2->id, 'position' => 1, 'source_type' => 'template_item', 'title' => 'Song 2']);

    $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");
    $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_item_position)->toBe(2);
});

test('next persists current_rundown_id so now-playing can match', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3", 'type' => 'music', 'title' => 'Song']);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $rundown->id, 'media_file_id' => $file->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'Song']);

    $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($rundown->id);
});

test('next returns empty string when rundown is exhausted and no next rundown', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3", 'type' => 'music', 'title' => 'Song']);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $rundown->id, 'media_file_id' => $file->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'Song']);

    // Ersten Track holen (Position wird auf 1 gesetzt)
    $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    // Zweiten Aufruf → keine weiteren Items, kein Folge-Rundown → leer
    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())->toBe('');
});

test('hard-start rundown cuts overhang and takes over at the hour', function () {
    Storage::fake('local');
    $this->travelTo(today()->setHour(12)->setMinute(5));

    $fileA = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/a.mp3", 'type' => 'music', 'title' => 'A']);
    $fileB = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/b.mp3", 'type' => 'music', 'title' => 'B']);

    // Überhängender Rundown der Vorstunde (11:00), noch nicht erschöpft
    $overhang = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 11,
        'status' => 'ready',
        'start_mode' => 'soft',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $overhang->id, 'media_file_id' => $fileA->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'A0']);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $overhang->id, 'media_file_id' => $fileA->id, 'position' => 1, 'source_type' => 'template_item', 'title' => 'A1']);

    // Hard-Start-Rundown der aktuellen Stunde (12:00)
    $hard = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 12,
        'status' => 'ready',
        'start_mode' => 'hard',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $hard->id, 'media_file_id' => $fileB->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'B0']);

    // State spielt noch den Überhang
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $overhang->id,
        'current_item_position' => 1,
    ]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    // Sollte auf den Hard-Rundown gewechselt sein und dessen Track 0 liefern
    $response->assertStatus(200);
    expect($response->getContent())->toContain('/media/'.$this->station->slug.'/'.$fileB->id);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($hard->id);
    // Der Überhang wird NICHT vom Pull-Cursor auf 'played' gesetzt – das passiert
    // erst, wenn now_playing tatsächlich auf den Hard-Rundown wechselt.
    expect($overhang->fresh()->status)->toBe('ready');
});

test('now-playing marks earlier rundowns as played (airplay-driven)', function () {
    Storage::fake('local');
    $this->travelTo(today()->setHour(12)->setMinute(5));

    $fileA = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/a.mp3", 'type' => 'music', 'title' => 'A']);
    $fileB = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/b.mp3", 'type' => 'music', 'title' => 'B']);

    $earlier = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 11,
        'status' => 'ready', 'start_mode' => 'soft',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $earlier->id, 'media_file_id' => $fileA->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'A0']);

    $current = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 12,
        'status' => 'ready', 'start_mode' => 'soft',
    ]);
    $itemB = GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $current->id, 'media_file_id' => $fileB->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'B0']);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $current->id,
        'current_item_position' => 1,
    ]);

    // Airplay meldet einen Track aus dem 12:00-Rundown
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", ['filename' => 'b.mp3'])
        ->assertStatus(200);

    expect($earlier->fresh()->status)->toBe('played');  // früher → gespielt
    expect($current->fresh()->status)->toBe('ready');   // aktueller → bleibt ready
    expect(LiquidsoapState::where('station_id', $this->station->id)->first()->now_playing_item_id)->toBe($itemB->id);
});

test('hard-start works via the live playlist even if the rundown snapshot is soft', function () {
    Storage::fake('local');
    $this->travelTo(today()->setHour(12)->setMinute(5));

    $fileA = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/a.mp3", 'type' => 'music', 'title' => 'A']);
    $fileB = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/b.mp3", 'type' => 'music', 'title' => 'B']);

    $overhang = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 11,
        'status' => 'ready', 'start_mode' => 'soft',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $overhang->id, 'media_file_id' => $fileA->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'A0']);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $overhang->id, 'media_file_id' => $fileA->id, 'position' => 1, 'source_type' => 'template_item', 'title' => 'A1']);

    // Playlist ist HART, aber der generierte Rundown-Snapshot steht noch auf soft
    $playlist = $this->station->playlists()->create(['name' => 'P', 'playback_mode' => 'sequential', 'start_mode' => 'hard']);

    $hard = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 12,
        'status' => 'ready', 'start_mode' => 'soft', 'playlist_id' => $playlist->id,
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $hard->id, 'media_file_id' => $fileB->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'B0']);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $overhang->id,
        'current_item_position' => 1,
    ]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())->toContain('/media/'.$this->station->slug.'/'.$fileB->id);
    expect(LiquidsoapState::where('station_id', $this->station->id)->first()->current_rundown_id)->toBe($hard->id);
});

test('soft-start rundown lets the overhang finish first', function () {
    Storage::fake('local');
    $this->travelTo(today()->setHour(12)->setMinute(5));

    $fileA = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/a.mp3", 'type' => 'music', 'title' => 'A']);
    $fileB = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'file_path' => "tenants/{$this->station->tenant_id}/media/b.mp3", 'type' => 'music', 'title' => 'B']);

    $overhang = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 11,
        'status' => 'ready', 'start_mode' => 'soft',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $overhang->id, 'media_file_id' => $fileA->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'A0']);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $overhang->id, 'media_file_id' => $fileA->id, 'position' => 1, 'source_type' => 'template_item', 'title' => 'A1']);

    // Folgestunde ist SOFT
    $soft = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 12,
        'status' => 'ready', 'start_mode' => 'soft',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $soft->id, 'media_file_id' => $fileB->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'B0']);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $overhang->id,
        'current_item_position' => 1,
    ]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    // Überhang läuft weiter → Track A1, nicht B0
    $response->assertStatus(200);
    expect($response->getContent())->toContain('/media/'.$this->station->slug.'/'.$fileA->id);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($overhang->id);
});

// ── /api/liquidsoap/{slug}/connect ──────────────────────────────────────────

test('connect resumes the pull cursor at the actual airplay position', function () {
    Storage::fake('local');
    $this->travelTo(today()->setHour(10)->setMinute(8));

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 10,
        'status' => 'ready',
    ]);

    $items = collect(range(0, 5))->map(fn ($pos) => GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/f{$pos}.mp3", 'title' => "F{$pos}"])->id,
        'position' => $pos,
        'source_type' => 'template_item',
        'title' => "F{$pos}",
        'duration_seconds' => 180,
    ]));

    // Airplay läuft seit 30 s auf File 1 (Position 0) – Track läuft noch (180 s).
    // Pull-Cursor ist durch prefetch aber schon auf 4 vorausgeeilt.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 4,
        'now_playing_item_id' => $items[0]->id,
        'now_playing_started_at' => now()->subSeconds(30),
    ]);

    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/connect")
        ->assertStatus(200);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_item_position)->toBe(0)
        ->and($state->current_rundown_id)->toBe($rundown->id);
});

test('connect catches up to the wall clock when the old position is long overdue', function () {
    Storage::fake('local');
    $this->travelTo(today()->setHour(11)->setMinute(8));

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 11,
        'status' => 'ready',
        // Regulär vorab geplant (vor Stundenbeginn) → Wanduhr-Catch-up greift.
        'generated_at' => today()->setTime(9, 0),
    ]);

    // Geplante Sendezeiten: F0 11:00, F1 11:04, F2 11:09, F3 11:14
    $times = ['11:00:00', '11:04:00', '11:09:00', '11:14:00'];
    $items = collect($times)->map(fn ($time, $pos) => GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/f{$pos}.mp3", 'title' => "F{$pos}"])->id,
        'position' => $pos,
        'source_type' => 'template_item',
        'title' => "F{$pos}",
        'duration_seconds' => 240,
        'absolute_broadcast_at' => today()->setTimeFromTimeString($time),
    ]));

    // Kaltstart: kein now_playing. Erwartung: um 11:08 ist F1 (11:04) dran, nicht F0.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => null,
        'current_item_position' => 0,
    ]);

    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/connect")
        ->assertStatus(200);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($rundown->id)
        ->and($state->current_item_position)->toBe(1);
});

test('connect starts a freshly regenerated rundown from the top instead of catching up', function () {
    Storage::fake('local');
    $this->travelTo(today()->setHour(11)->setMinute(8));

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 11,
        'status' => 'ready',
        // ERST während der laufenden Stunde (neu) generiert → davon wurde nichts gesendet.
        'generated_at' => today()->setTime(11, 8),
    ]);

    $times = ['11:00:00', '11:04:00', '11:09:00'];
    collect($times)->each(fn ($time, $pos) => GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/f{$pos}.mp3", 'title' => "F{$pos}"])->id,
        'position' => $pos,
        'source_type' => 'template_item',
        'title' => "F{$pos}",
        'duration_seconds' => 240,
        'absolute_broadcast_at' => today()->setTimeFromTimeString($time),
    ]));

    // Zustand nach Regenerierung: Cursor auf 0, now_playing-FK genullt.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 0,
        'now_playing_item_id' => null,
    ]);

    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/connect")
        ->assertStatus(200);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($rundown->id)
        ->and($state->current_item_position)->toBe(0);
});

test('connect clears a stale cursor so the next pull resolves fresh', function () {
    Storage::fake('local');
    $this->travelTo(today()->setHour(10)->setMinute(8));

    // now_playing zeigt auf einen Rundown von vor 2 Stunden (z. B. nach längerer Downtime).
    $old = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 8,
        'status' => 'ready',
    ]);
    $oldItem = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $old->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/old.mp3", 'title' => 'Old'])->id,
        'position' => 3,
        'source_type' => 'template_item',
        'title' => 'Old',
    ]);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $old->id,
        'current_item_position' => 6,
        'now_playing_item_id' => $oldItem->id,
        'now_playing_started_at' => now()->subHours(2),
    ]);

    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/connect")
        ->assertStatus(200);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBeNull()
        ->and($state->current_item_position)->toBe(0)
        ->and($state->now_playing_item_id)->toBeNull();
});

// ── /api/liquidsoap/{slug}/now-playing ──────────────────────────────────────

test('now-playing updates state', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/current.mp3",
        'type' => 'music',
        'title' => 'Current Song',
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Current Song',
    ]);

    // State mit aktivem Rundown anlegen
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 1,
    ]);

    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", [
            'filename' => 'current.mp3',
        ])
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->now_playing_item_id)->toBe($item->id);
    expect($state->now_playing_started_at)->not->toBeNull();
});

test('now-playing matches by annotated item id even when the filename does not match', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/current.mp3",
        'type' => 'music',
        'title' => 'Current Song',
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Current Song',
    ]);

    // filename ist ein nichtssagender Temp-Pfad (wie bei Remote-URLs) – nur die ID greift.
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", [
            'item_id' => (string) $item->id,
            'filename' => '/tmp/liq/abc123.mp3',
        ])
        ->assertStatus(200);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->now_playing_item_id)->toBe($item->id);
});

// ── /api/liquidsoap/{slug}/live (harbor on_connect/on_disconnect) ────────────

test('live endpoint marks the station live on connect', function () {
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/live", ['connected' => true])
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    expect(LiquidsoapState::where('station_id', $this->station->id)->first()->live_active)->toBeTrue();
});

test('live endpoint clears the live state on disconnect', function () {
    LiquidsoapState::create(['station_id' => $this->station->id, 'live_active' => true, 'live_started_at' => now()]);

    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/live", ['connected' => false])
        ->assertStatus(200);

    expect(LiquidsoapState::where('station_id', $this->station->id)->first()->live_active)->toBeFalse();
});

test('live endpoint rejects a missing token', function () {
    $this->postJson("/api/liquidsoap/{$this->station->slug}/live", ['connected' => true])
        ->assertStatus(401);
});

// ── /api/stream/media/{slug}/{mediaFile} ────────────────────────────────────

test('media endpoint returns 404 for unknown file', function () {
    $this->get(signedDeliveryUrl('liquidsoap.media', ['slug' => $this->station->slug, 'mediaFile' => 999999]))
        ->assertStatus(404);
});

test('media endpoint forbids a file the station neither owns nor links', function () {
    $other = Station::factory()->create();
    $foreign = MediaFile::factory()->create(['tenant_id' => $other->tenant_id]);

    $this->get(signedDeliveryUrl('liquidsoap.media', ['slug' => $this->station->slug, 'mediaFile' => $foreign->id]))
        ->assertStatus(403);
});

test('media endpoint serves a file uploaded through a sibling station', function () {
    Storage::fake('local');
    $sibling = Station::factory()->create([
        'user_id' => $this->station->user_id,
        'tenant_id' => $this->station->tenant_id,
    ]);
    $path = "tenants/{$sibling->tenant_id}/media/shared.mp3";
    Storage::disk('local')->put($path, 'audio-bytes');

    $shared = MediaFile::factory()->create([
        'tenant_id' => $sibling->tenant_id,
        'file_path' => $path,
    ]);

    $this->get(signedDeliveryUrl('liquidsoap.media', ['slug' => $this->station->slug, 'mediaFile' => $shared->id]))
        ->assertStatus(200);
});

// ── Live-Metadaten: "Interpret - Titel" auftrennen ──────────────────────────

test('now-playing splits a combined live title into artist and title', function () {
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", [
            'title' => 'Anna-Maria Zimmermann - Himmelblaue Augen',
        ])
        ->assertStatus(200)
        ->assertJson(['ok' => true, 'live' => true]);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->live_active)->toBeTrue()
        ->and($state->live_artist)->toBe('Anna-Maria Zimmermann')
        ->and($state->live_title)->toBe('Himmelblaue Augen');
});

test('now-playing keeps an explicit live artist untouched', function () {
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", [
            'title' => 'Himmelblaue Augen',
            'artist' => 'Anna-Maria Zimmermann',
        ])
        ->assertStatus(200);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->live_artist)->toBe('Anna-Maria Zimmermann')
        ->and($state->live_title)->toBe('Himmelblaue Augen');
});

test('now-playing leaves a live title without a separator as title only', function () {
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", [
            'title' => 'Nur ein Sendungstitel',
        ])
        ->assertStatus(200);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->live_title)->toBe('Nur ein Sendungstitel')
        ->and($state->live_artist)->toBeNull();
});
