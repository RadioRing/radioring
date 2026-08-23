<?php

use App\Models\ExternalSource;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
