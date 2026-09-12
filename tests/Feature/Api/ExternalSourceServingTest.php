<?php

use App\Models\ExternalSource;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Models\StationLog;
use App\Models\User;
use App\Services\AudioMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->token = $this->station->api_token;
    $this->travelTo(today()->setHour(10)->setMinute(5));
});

function externalRundownItem(Station $station, array $itemAttributes, ?ExternalSource $source = null): GeneratedPlaylistItem
{
    $source ??= ExternalSource::factory()->create(['station_id' => $station->id, 'kind' => 'url', 'url' => 'https://example.com/show.mp3']);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 10,
        'status' => 'ready',
    ]);

    return GeneratedPlaylistItem::factory()->create(array_merge([
        'generated_playlist_id' => $rundown->id,
        'external_source_id' => $source->id,
        'position' => 0,
        'source_type' => 'external',
        'title' => 'Externe Quelle',
    ], $itemAttributes));
}

test('next serves the prepared copy with the loudness gain annotation', function () {
    config(['radioring.loudness.enabled' => true, 'radioring.loudness.target_lufs' => -14.0]);

    $path = "stations/{$this->station->slug}/prepared/99.mp3";
    Storage::disk('local')->put($path, 'AUDIO');

    $item = externalRundownItem($this->station, [
        'prepared_path' => $path,
        'prepared_at' => now(),
        'loudness_lufs' => -20.0,
        'loudness_true_peak' => -8.0,
    ]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())
        ->toContain('/api/stream/prepared/'.$this->station->slug.'/'.$item->id)
        ->toContain('radioring_item_id="'.$item->id.'"')
        ->toContain('liq_amplify="6 dB"');
});

test('next annotates a fade-in when the source enables it', function () {
    $path = "stations/{$this->station->slug}/prepared/77.mp3";
    Storage::disk('local')->put($path, 'AUDIO');

    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/show.mp3', 'fade_in' => true,
    ]);
    externalRundownItem($this->station, ['prepared_path' => $path, 'prepared_at' => now()], $source);

    config(['radioring.fade_in_seconds' => 2.0]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())->toContain('liq_fade_in="2"');
});

test('next omits the fade-in annotation when the source disables it', function () {
    $path = "stations/{$this->station->slug}/prepared/78.mp3";
    Storage::disk('local')->put($path, 'AUDIO');

    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/show.mp3', 'fade_in' => false,
    ]);
    externalRundownItem($this->station, ['prepared_path' => $path, 'prepared_at' => now()], $source);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())->not->toContain('liq_fade_in');
});

test('next downloads inline and serves local url when not yet prepared', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'https://syndi.example/live.mp3',
    ]);

    $item = externalRundownItem($this->station, ['prepared_path' => null], $source);

    Http::fake(['syndi.example/*' => Http::response('AUDIO-BYTES', 200)]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())
        ->toContain('radioring_item_id="'.$item->id.'"')
        ->toContain('/api/stream/prepared/'.$this->station->slug.'/'.$item->id)
        ->not->toContain('syndi.example')
        ->not->toContain('liq_amplify');
});

test('next skips an external item when the inline download fails', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'https://syndi.example/live.mp3',
    ]);

    externalRundownItem($this->station, ['prepared_path' => null], $source);

    Http::fake(['syndi.example/*' => Http::response('', 503)]);

    // Kein zweites Item → nach dem Überspringen kein Track mehr → leere Antwort.
    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())->toBe('');

    // Skip needs to be in the event log
    $log = StationLog::where('event', StationLog::EVENT_EXTERNAL_FAILED)->sole();
    expect($log->station_id)->toBe($this->station->id)
        ->and($log->title)->toBe('Externe Quelle')
        ->and($log->message)->toContain('503');
});

test('next skips an external news item when the station has no laut.fm output', function () {
    $source = ExternalSource::factory()->news()->create(['station_id' => $this->station->id]);
    externalRundownItem($this->station, ['prepared_path' => null], $source);

    // Kein zweites Item, kein laut.fm-Ausgang → resolveUrl null → übersprungen → leer.
    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    $response->assertStatus(200);
    expect($response->getContent())->toBe('');
});

test('prepared endpoint serves the cached file with a valid token', function () {
    $path = "stations/{$this->station->slug}/prepared/5.mp3";
    Storage::disk('local')->put($path, 'AUDIO-BYTES');

    $item = externalRundownItem($this->station, ['prepared_path' => $path]);

    $this->get(signedDeliveryUrl('liquidsoap.prepared', ['slug' => $this->station->slug, 'item' => $item->id]))
        ->assertStatus(200);
});

test('prepared endpoint rejects a wrong token', function () {
    $item = externalRundownItem($this->station, ['prepared_path' => "stations/{$this->station->slug}/prepared/5.mp3"]);

    $this->get("/api/stream/prepared/{$this->station->slug}/{$item->id}?signature=wrong&expires=9999999999")
        ->assertStatus(401);
});

test('prepared endpoint forbids an item of another station', function () {
    $other = Station::factory()->create();
    $foreignItem = externalRundownItem($other, ['prepared_path' => "stations/{$other->slug}/prepared/5.mp3"]);

    $this->get(signedDeliveryUrl('liquidsoap.prepared', ['slug' => $this->station->slug, 'item' => $foreignItem->id]))
        ->assertStatus(403);
});

test('next annotates the broadcast title instead of the internal name', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'https://example.com/show.mp3',
        'name' => 'Morgenshow #2', 'broadcast_title' => 'Morgenshow',
    ]);

    $path = "stations/{$this->station->slug}/prepared/77.mp3";
    Storage::disk('local')->put($path, 'AUDIO');

    externalRundownItem($this->station, [
        'prepared_path' => $path,
        'prepared_at' => now(),
        // The frozen item title is the operator's label, as copied from the source.
        'title' => 'Morgenshow #2',
    ], $source);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    expect($response->getContent())
        ->toContain('title="Morgenshow"')
        ->not->toContain('#2');
});

test('next falls back to the item title when no broadcast title is set', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'https://example.com/show.mp3',
        'name' => 'Wetterbericht', 'broadcast_title' => null,
    ]);

    $path = "stations/{$this->station->slug}/prepared/78.mp3";
    Storage::disk('local')->put($path, 'AUDIO');

    externalRundownItem($this->station, [
        'prepared_path' => $path,
        'prepared_at' => now(),
        'title' => 'Wetterbericht',
    ], $source);

    expect($this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next")->getContent())
        ->toContain('title="Wetterbericht"');
});

test('the inline fallback prepares the item fully: trim, loudness and duration', function () {
    config(['radioring.loudness.enabled' => true, 'radioring.loudness.target_lufs' => -14.0]);

    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'https://files.example.com/show.mp3',
        'normalize' => true, 'trim_leading_silence' => true, 'expected_duration_seconds' => 300,
    ]);

    // Not prepared in advance: the job has not reached this item yet.
    $item = externalRundownItem($this->station, ['prepared_path' => null], $source);

    Http::fake(['*' => Http::response('AUDIO', 200)]);
    Process::fake(['*' => Process::result(output: '', errorOutput: "[Parsed_loudnorm_0 @ 0x0]\n{\n\t\"input_i\" : \"-20.0\",\n\t\"input_tp\" : \"-8.0\",\n\t\"input_lra\" : \"7.0\",\n\t\"input_thresh\" : \"-30.0\"\n}")]);

    $this->mock(AudioMetadataService::class)
        ->shouldReceive('read')->once()->andReturn([
            'title' => null, 'artist' => null, 'album' => null, 'duration' => 1802,
        ]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    // Trim ran, loudness was measured and travels along as a gain.
    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'silenceremove'));
    expect($response->getContent())->toContain('liq_amplify="6 dB"');

    // And the measured length reached the source instead of the guess.
    expect($item->fresh()->loudness_lufs)->toBe(-20.0)
        ->and($source->fresh()->expected_duration_seconds)->toBe(1802);
});

test('one pull gives up after a few unplayable elements instead of eating the hour', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'https://syndi.example/live.mp3',
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(), 'broadcast_hour' => 10, 'status' => 'ready',
    ]);

    foreach (range(0, 9) as $position) {
        GeneratedPlaylistItem::factory()->create([
            'generated_playlist_id' => $rundown->id,
            'external_source_id' => $source->id,
            'position' => $position,
            'source_type' => 'external',
            'title' => 'Externe Quelle',
            'prepared_path' => null,
        ]);
    }

    Http::fake(['syndi.example/*' => Http::response('', 503)]);

    $response = $this->withToken($this->token)->get("/api/liquidsoap/{$this->station->slug}/next");

    // Empty answer: next_track turns it into null() and request.dynamic asks again.
    $response->assertStatus(200);
    expect($response->getContent())->toBe('');

    // Four elements consumed at most (the first plus three skips), not the whole hour.
    expect(LiquidsoapState::where('station_id', $this->station->id)->value('current_item_position'))
        ->toBeLessThanOrEqual(4);
    expect(StationLog::where('event', StationLog::EVENT_EXTERNAL_FAILED)->count())
        ->toBeLessThanOrEqual(4);
});
