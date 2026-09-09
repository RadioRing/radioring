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

test('does not cut the same hard rundown twice when now_playing was cleared', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);
    $hard = hardSetup($this->station);

    LiquidsoapState::create(['station_id' => $this->station->id, 'current_rundown_id' => null, 'current_item_position' => 3]);

    // First run should cut at full hour
    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->once()->andReturnTrue();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();

    $this->travelTo(today()->setHour(12)->setMinute(23));
    LiquidsoapState::where('station_id', $this->station->id)->update([
        'now_playing_item_id' => null,
        'current_item_position' => 7,
    ]);

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_item_position)->toBe(7)
        ->and($state->hard_start_committed_rundown_id)->toBe($hard->id);
});

test('cuts a hard rundown that already started before its full hour', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);
    $hard = hardSetup($this->station);
    $hardItem = $hard->items()->first();

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $hard->id,
        'current_item_position' => 1,
        'now_playing_item_id' => $hardItem->id,
        'now_playing_started_at' => today()->setTime(11, 57, 0),
    ]);

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->once()->andReturnTrue();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($hard->id)
        ->and($state->current_item_position)->toBe(0);
});

test('does not cut once the hard start window has passed', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);
    hardSetup($this->station);

    $this->travelTo(today()->setHour(12)->setMinute(41));

    LiquidsoapState::create(['station_id' => $this->station->id, 'current_rundown_id' => null, 'current_item_position' => 5]);

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->never();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_item_position)->toBe(5);
});

test('cuts again for the next hour despite an earlier commit', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);
    $previous = hardSetup($this->station);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $previous->id,
        'current_item_position' => 4,
        'hard_start_committed_rundown_id' => $previous->id,
    ]);

    $next = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 13,
        'status' => 'ready', 'start_mode' => 'hard',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $next->id, 'position' => 0, 'source_type' => 'news_weather', 'title' => 'Nachrichten + Wetter']);

    $this->travelTo(today()->setHour(13)->setMinute(0));

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->once()->andReturnTrue();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($next->id)
        ->and($state->current_item_position)->toBe(0)
        ->and($state->hard_start_committed_rundown_id)->toBe($next->id);
});

test('announces the next hard start before its full hour and leaves the cursor alone', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);

    $running = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 12,
        'status' => 'ready', 'start_mode' => 'soft',
    ]);

    $hard = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 13,
        'status' => 'ready', 'start_mode' => 'hard',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $hard->id, 'position' => 0, 'source_type' => 'news_weather', 'title' => 'Nachrichten']);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $running->id,
        'current_item_position' => 5,
    ]);

    $this->travelTo(today()->setTime(12, 59, 0));

    // 60 Sekunden Vorlauf: der Container legt den Fade so, dass der Cut auf 13:00:00 faellt.
    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->once()->with(Mockery::any(), 60.0)->andReturnTrue();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();

    // Der Cursor darf NICHT mitwandern: sonst zoege der Prefetch die Nachrichten noch vor
    // dem Schnitt, und das set_queue([]) des Cuts wuerfe genau sie weg.
    expect($state->hard_start_committed_rundown_id)->toBe($hard->id)
        ->and($state->current_rundown_id)->toBe($running->id)
        ->and($state->current_item_position)->toBe(5);
});

test('announces a hard start only once', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);

    $hard = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 13,
        'status' => 'ready', 'start_mode' => 'hard',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $hard->id, 'position' => 0, 'source_type' => 'news_weather', 'title' => 'Nachrichten']);

    LiquidsoapState::create(['station_id' => $this->station->id, 'current_item_position' => 5]);

    $this->travelTo(today()->setTime(12, 59, 0));

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->once()->andReturnTrue();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();

    // Der Lauf zur vollen Stunde darf nicht ein zweites Mal schneiden.
    $this->travelTo(today()->setTime(13, 0, 0));
    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();
});

test('does not announce a soft start', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);

    $soft = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id, 'broadcast_date' => today(), 'broadcast_hour' => 13,
        'status' => 'ready', 'start_mode' => 'soft',
    ]);
    GeneratedPlaylistItem::factory()->create(['generated_playlist_id' => $soft->id, 'position' => 0, 'source_type' => 'template_item', 'title' => 'S0']);

    LiquidsoapState::create(['station_id' => $this->station->id, 'current_item_position' => 5]);

    $this->travelTo(today()->setTime(12, 59, 0));

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->never();

    $this->artisan('radioring:enforce-hard-starts')->assertSuccessful();
});
