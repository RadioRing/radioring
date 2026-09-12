<?php

use App\Livewire\Dashboard;
use App\Models\ExternalSource;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    $this->user->setCurrentStation($this->station);
    $this->actingAs($this->user);

    $this->rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    $this->source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'kind' => 'url',
        'url' => 'https://example.com/show.mp3',
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function preparationItem(array $attributes): GeneratedPlaylistItem
{
    return GeneratedPlaylistItem::factory()->create(array_merge([
        'generated_playlist_id' => test()->rundown->id,
        'external_source_id' => test()->source->id,
        'source_type' => 'external',
        'duration_seconds' => 600,
    ], $attributes));
}

test('the playlist shows the preparation state of every external element', function () {
    preparationItem([
        'position' => 0,
        'title' => 'Part one',
        'prepared_path' => "stations/{$this->station->slug}/prepared/1.mp3",
        'prepared_at' => now(),
    ]);
    preparationItem(['position' => 1, 'title' => 'Part two']);
    preparationItem([
        'position' => 2,
        'title' => 'Part three',
        'prepare_failed_at' => now()->subMinute(),
        'prepare_attempts' => 2,
    ]);

    $this->source->update(['last_error' => 'HTTP 503']);

    Livewire::test(Dashboard::class)
        ->assertSee('Prepared, ready to play')
        ->assertSee('Not fetched yet')
        ->assertSee('Fetching failed: HTTP 503')
        ->assertSee('1 of 3 external elements ready');
});

test('the counter is left out when nothing external is coming up', function () {
    GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $this->rundown->id,
        'position' => 0,
        'source_type' => 'media',
        'title' => 'Just a track',
        'duration_seconds' => 180,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Just a track')
        ->assertDontSee('external elements ready');
});
