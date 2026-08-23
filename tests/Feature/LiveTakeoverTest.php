<?php

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use App\Services\LiquidsoapScriptGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->token = $this->station->api_token;
});

test('metadata without an item id marks the harbor as live', function () {
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", [
            'title' => 'Live-Show',
            'artist' => 'DJ Klaas',
        ])
        ->assertOk()
        ->assertJson(['live' => true]);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();

    expect($state->live_active)->toBeTrue();
    expect($state->live_title)->toBe('Live-Show');
    expect($state->live_artist)->toBe('DJ Klaas');
    expect($state->live_started_at)->not->toBeNull();
});

test('a resolved track ends the live state', function () {
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", ['title' => 'Live']);

    expect(LiquidsoapState::where('station_id', $this->station->id)->first()->live_active)->toBeTrue();

    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
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
        'title' => 'Regular',
    ]);

    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", ['item_id' => $item->id])
        ->assertOk();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->live_active)->toBeFalse();
    expect($state->live_title)->toBeNull();
    expect($state->now_playing_item_id)->toBe($item->id);
});

test('empty metadata without a title does not falsely trigger live', function () {
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", [
            'title' => '',
            'artist' => '',
        ])
        ->assertOk()
        ->assertJsonMissing(['live' => true]);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state?->live_active ?? false)->toBeFalse();
});

test('the live started timestamp is kept stable across metadata updates', function () {
    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", ['title' => 'Song 1']);

    $first = LiquidsoapState::where('station_id', $this->station->id)->first()->live_started_at;

    $this->travel(30)->seconds();

    $this->withToken($this->token)
        ->postJson("/api/liquidsoap/{$this->station->slug}/now-playing", ['title' => 'Song 2']);

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->live_title)->toBe('Song 2');
    expect($state->live_started_at->timestamp)->toBe($first->timestamp);
});

test('the generated script reports title and artist via json', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station);

    expect($script)
        ->toContain('json.stringify')
        ->toContain('title = m["title"]')
        ->toContain('artist = m["artist"]');
});
