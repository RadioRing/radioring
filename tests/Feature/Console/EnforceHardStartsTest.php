<?php

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use App\Services\LiquidsoapCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create(['user_id' => User::factory()]);
    $this->travelTo(today()->setHour(12)->setMinute(0));
});

function hardSetup(Station $station): GeneratedPlaylist
{
    $file = MediaFile::factory()->create(['tenant_id' => $station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$station->tenant_id}/media/b.mp3", 'title' => 'B']);

    $hard = GeneratedPlaylist::factory()->create([
        'station_id' => $station->id, 'broadcast_date' => today(), 'broadcast_hour' => 12,
        'status' => 'ready', 'start_mode' => 'hard',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $hard->id, 'media_file_id' => $file->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'B0']);

    return $hard;
}

test('enforces hard start on a running station and skips', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);
    $hard = hardSetup($this->station);

    // State spielt noch einen anderen Rundown (Überhang)
    LiquidsoapState::create(['station_id' => $this->station->id, 'current_rundown_id' => null, 'current_item_position' => 3]);

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->once()->andReturnTrue();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($hard->id)
        ->and($state->current_item_position)->toBe(0);
});

test('does not skip when station container is not running', function () {
    $this->station->stream()->create(['container_name' => 'x', 'status' => 'stopped']);
    hardSetup($this->station);

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->never();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();
});

test('does not skip again once the hard rundown is actually on air', function () {
    $this->station->stream()->create(['container_name' => 'x', 'status' => 'running']);
    $hard = hardSetup($this->station);
    $hardItem = $hard->items()->first();

    // Hörbar (now_playing) bereits im Hard-Rundown → nichts mehr zu tun.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $hard->id,
        'current_item_position' => 1,
        'now_playing_item_id' => $hardItem->id,
    ]);

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->never();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();
});

test('enforces the cut even when the pull cursor already raced into the hard rundown', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);
    $hard = hardSetup($this->station);

    // Vorstunde, deren Track durch den Prefetch-Puffer hörbar noch läuft (Überhang).
    $prev = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 11,
        'status' => 'ready', 'start_mode' => 'soft',
    ]);
    $prevFile = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/a.mp3", 'title' => 'A']);
    $prevItem = GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $prev->id, 'media_file_id' => $prevFile->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'A0']);

    // Pull-Cursor steht durch prefetch=3 schon im Hard-Rundown, das Airplay aber
    // noch in der Vorstunde – früher blieb der Cut hier fälschlich aus.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $hard->id,
        'current_item_position' => 2,
        'now_playing_item_id' => $prevItem->id,
    ]);

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->once()->andReturnTrue();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($hard->id)
        ->and($state->current_item_position)->toBe(0);
});
