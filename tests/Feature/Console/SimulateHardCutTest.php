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
    $this->travelTo(today()->setHour(12)->setMinute(30));
});

function onAirItem(Station $station): GeneratedPlaylistItem
{
    $file = MediaFile::factory()->create([
        'tenant_id' => $station->tenant_id,
        'type' => 'music',
        'file_path' => "tenants/{$station->tenant_id}/media/a.mp3",
        'title' => 'A',
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $station->id, 'broadcast_date' => today(), 'broadcast_hour' => 12,
        'status' => 'ready', 'start_mode' => 'soft',
    ]);

    return GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id, 'media_file_id' => $file->id,
        'position' => 2, 'source_type' => 'template_item', 'title' => 'A2',
    ]);
}

test('announces the cut with a lead time and rewinds the cursor behind the running track', function () {
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);
    $item = onAirItem($this->station);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $item->generated_playlist_id,
        // Prefetch has the cursor running ahead of what is on air.
        'current_item_position' => 6,
        'now_playing_item_id' => $item->id,
        'now_playing_title' => 'A2',
        'now_playing_started_at' => now(),
    ]);

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->once()->with(Mockery::type(Station::class), 4.0)->andReturnTrue();

    $this->artisan('radioring:simulate-hard-cut', ['station' => $this->station->slug, '--lead' => '4'])
        ->assertSuccessful();

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_item_position)->toBe(3);
});

test('fails when the station container is not running', function () {
    $this->station->stream()->create(['container_name' => 'x', 'status' => 'stopped']);

    $this->mock(LiquidsoapCommandService::class)
        ->shouldReceive('skip')->never();

    $this->artisan('radioring:simulate-hard-cut', ['station' => $this->station->slug])
        ->assertFailed();
});
