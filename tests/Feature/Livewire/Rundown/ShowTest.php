<?php

use App\Livewire\Rundown\Show;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

test('played fade follows airplay, not the prefetch cursor', function () {
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

    // Airplay auf Position 1, Pull-Cursor durch prefetch schon auf 4.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $rundown->id,
        'current_item_position' => 4,
        'now_playing_item_id' => $items[1]->id,
        'now_playing_started_at' => now(),
    ]);

    Livewire::test(Show::class, ['date' => today()->toDateString(), 'hour' => 10])
        // Nur Position 0 gilt als gespielt (< playingPosition 1) – die vorgeladenen
        // Positionen 2 und 3 NICHT, obwohl sie unter dem Pull-Cursor (4) liegen.
        ->assertViewHas('playingPosition', 1)
        ->assertViewHas('lockedBelowPosition', 4);
});

test('playing position is detected even when the pull cursor raced into the next rundown', function () {
    $current = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 10,
        'status' => 'ready',
    ]);
    $playing = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $current->id,
        'media_file_id' => MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'file_path' => "tenants/{$this->station->tenant_id}/media/a.mp3", 'title' => 'A'])->id,
        'position' => 2,
        'source_type' => 'template_item',
        'title' => 'A',
    ]);

    $next = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 11,
        'status' => 'ready',
    ]);

    // Pull-Cursor steht schon im nächsten Rundown, Airplay aber noch im 10-Uhr-Rundown.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $next->id,
        'current_item_position' => 1,
        'now_playing_item_id' => $playing->id,
        'now_playing_started_at' => now(),
    ]);

    Livewire::test(Show::class, ['date' => today()->toDateString(), 'hour' => 10])
        ->assertViewHas('playingPosition', 2)
        ->assertViewHas('lockedBelowPosition', -1);
});
