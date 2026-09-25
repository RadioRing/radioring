<?php

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\StationLog;
use App\Services\LiquidsoapScriptGenerator;
use App\Services\LiquidsoapStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->station = Station::factory()->create();
    $this->token = $this->station->api_token;
});

// ── the generated script ────────────────────────────────────────────────────

test('the emergency loop plays local files behind the programme and before silence', function () {
    config()->set('radioring.emergency.directory', '/app/liquidsoap/emergency');

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('emergency = playlist(id="emergency", mode="randomize", reload_mode="rounds", reload=1, "/app/liquidsoap/emergency/emergency.m3u")')
        // blank() stays last: nothing selected, nothing synced, every file broken.
        ->toContain('radio = fallback(track_sensitive=false, [live, program, emergency, blank()])');
});

test('the emergency branch is marked so its tracks are not taken for a live takeover', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('emergency = metadata.map(fun (_) -> [("radioring_source", "emergency")], emergency)')
        ->toContain('source = m["radioring_source"]');
});

test('the emergency branch applies the measured gain only while loudness correction is on', function () {
    expect(app(LiquidsoapScriptGenerator::class)->generate($this->station))
        ->toContain('emergency = amplify(1., override="liq_amplify", emergency)');

    config()->set('radioring.loudness.enabled', false);

    expect(app(LiquidsoapScriptGenerator::class)->generate($this->station))
        ->not->toContain('emergency = amplify');
});

// ── now playing ────────────────────────────────────────────────────────────

function reportEmergency(Station $station, MediaFile $file, array $payload = []): TestResponse
{
    return test()->withToken($station->api_token)
        ->postJson("/api/liquidsoap/{$station->slug}/now-playing", [
            'source' => 'emergency',
            'filename' => '/app/liquidsoap/emergency/'.$file->id.'-'.$file->updated_at->timestamp.'.mp3',
            'title' => 'ID3 title',
            'artist' => 'ID3 artist',
            ...$payload,
        ]);
}

test('an emergency track is reported as the emergency loop, not as a live takeover', function () {
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'title' => 'We are back shortly',
        'artist' => 'Station',
        'duration_seconds' => 42,
    ]);

    reportEmergency($this->station, $file)->assertOk()->assertJson(['emergency' => true]);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();

    // The library values win over the ID3 tags, like everywhere else.
    expect($state->live_active)->toBeFalse()
        ->and($state->now_playing_source_type)->toBe('emergency')
        ->and($state->now_playing_title)->toBe('We are back shortly')
        ->and($state->now_playing_artist)->toBe('Station')
        ->and($state->now_playing_duration_seconds)->toBe(42)
        ->and($state->now_playing_item_id)->toBeNull();

    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_LIVE_STARTED)->exists())->toBeFalse();
});

test('an unresolvable emergency file name still reports the loop', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    reportEmergency($this->station, $file, ['filename' => 'hand-placed.mp3'])->assertOk();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();

    expect($state->now_playing_source_type)->toBe('emergency')
        ->and($state->now_playing_title)->toBe('ID3 title')
        ->and($state->now_playing_duration_seconds)->toBeNull();
});

test('the protocol gets one line per episode, not per emergency track', function () {
    $first = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'duration_seconds' => 30]);
    $second = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'duration_seconds' => 30]);

    reportEmergency($this->station, $first)->assertOk();
    reportEmergency($this->station, $second)->assertOk();

    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_EMERGENCY_STARTED)->count())->toBe(1);
});

test('the returning programme ends the episode in the protocol', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'duration_seconds' => 30]);

    reportEmergency($this->station, $file)->assertOk();

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => (int) now()->format('G'),
        'status' => 'ready',
    ]);
    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Back on air',
    ]);

    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", ['item_id' => $item->id])
        ->assertOk();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();

    expect($state->now_playing_source_type)->not->toBe('emergency');
    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_EMERGENCY_STOPPED)->count())->toBe(1);
});

// ── the underrun verdict ───────────────────────────────────────────────────

test('a station on the emergency loop is still in an underrun', function () {
    config()->set('radioring.underrun_alert_seconds', 30);

    $state = LiquidsoapState::create([
        'station_id' => $this->station->id,
        'underrun_started_at' => now()->subMinutes(5),
        'now_playing_source_type' => 'emergency',
        'now_playing_title' => 'Emergency track',
        'now_playing_duration_seconds' => 120,
        'now_playing_started_at' => now()->subSeconds(10),
    ]);

    // The snapshot is fresh, so without the emergency exception this would read as the
    // programme being back on air.
    expect($state->onEmergency())->toBeTrue()
        ->and($state->isUnderrun())->toBeTrue()
        ->and($state->underrunSeconds())->toBeGreaterThanOrEqual(300);
});

test('the underrun protocol line says whether a loop took over', function () {
    config()->set('radioring.underrun_alert_seconds', 0);

    $withoutLoop = Station::factory()->create();
    app(LiquidsoapStateService::class)->pullNextItem($withoutLoop);

    expect(StationLog::where('station_id', $withoutLoop->id)->where('event', StationLog::EVENT_UNDERRUN)->first()->message)
        ->toContain('sending silence');

    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
    $this->station->emergencyItems()->attach($file->id, ['position' => 0]);

    app(LiquidsoapStateService::class)->pullNextItem($this->station);

    expect(StationLog::where('station_id', $this->station->id)->where('event', StationLog::EVENT_UNDERRUN)->first()->message)
        ->toContain('emergency loop');
});

// ── clean up ───────────────────────────────────────────────────────────────

test('deleting a media file or the station drops the selection with it', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
    $kept = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    $this->station->emergencyItems()->attach([
        $file->id => ['position' => 0],
        $kept->id => ['position' => 1],
    ]);

    $file->delete();

    expect($this->station->emergencyItems()->pluck('media_files.id')->all())->toBe([$kept->id]);

    $this->station->delete();

    expect(DB::table('station_emergency_items')->count())->toBe(0);
});
