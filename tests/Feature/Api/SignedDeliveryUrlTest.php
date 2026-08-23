<?php

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\MediaFile;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->station = Station::factory()->create();
    $this->token = $this->station->api_token;

    $this->file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => "tenants/{$this->station->tenant_id}/media/song.mp3",
    ]);

    Storage::disk('local')->put($this->file->file_path, 'audio');
});

function mediaUrlFor(Station $station, MediaFile $file, ?int $ttl = null): string
{
    return signedDeliveryUrl('liquidsoap.media', [
        'slug' => $station->slug,
        'mediaFile' => $file->id,
    ], $ttl);
}

test('a valid signature delivers the file', function () {
    $this->get(mediaUrlFor($this->station, $this->file))->assertOk();
});

test('an expired signature is refused', function () {
    $url = mediaUrlFor($this->station, $this->file, 60);

    $this->travel(2)->minutes();

    $this->get($url)->assertUnauthorized();
});

test('a tampered file id invalidates the signature', function () {
    $other = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);

    $url = mediaUrlFor($this->station, $this->file);
    $tampered = str_replace(
        '/'.$this->file->id.'?',
        '/'.$other->id.'?',
        $url,
    );

    $this->get($tampered)->assertUnauthorized();
});

test('a tampered expiry invalidates the signature', function () {
    $url = mediaUrlFor($this->station, $this->file, 60);

    $tampered = preg_replace('/expires=\d+/', 'expires='.now()->addYear()->timestamp, $url);

    $this->get($tampered)->assertUnauthorized();
});

test('the api token alone no longer opens the delivery endpoint', function () {
    // Das ist der eigentliche Gewinn: eine geloggte Auslieferungs-URL taugt nicht mehr
    // als Bearer-Token fuer /script, und der Token allein oeffnet die Auslieferung nicht.
    $this->get("/api/stream/media/{$this->station->slug}/{$this->file->id}?token={$this->token}")
        ->assertUnauthorized();
});

test('the delivery url no longer carries the api token', function () {
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'position' => 0,
        'media_file_id' => $this->file->id,
        'title' => 'Song',
    ]);

    $body = $this->withToken($this->token)
        ->get("/api/liquidsoap/{$this->station->slug}/next")
        ->getContent();

    expect($body)->not->toContain($this->token)
        ->and($body)->toContain('signature=');
});

test('the signature survives a different host', function () {
    // Relativ signiert: der Container erreicht die App je nach Aufbau intern oder
    // oeffentlich. Eine host-gebundene Signatur wuerde in einem der Faelle scheitern.
    $url = mediaUrlFor($this->station, $this->file);

    $this->get('http://intern.invalid'.$url)->assertOk();
});
