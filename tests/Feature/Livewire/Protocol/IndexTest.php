<?php

use App\Livewire\Protocol\Index;
use App\Models\Station;
use App\Models\StationLog;
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

test('protocol renders', function () {
    Livewire::test(Index::class)->assertOk();
});

test('it lists today\'s entries of the current station', function () {
    StationLog::factory()->create(['station_id' => $this->station->id, 'title' => 'Heute Song', 'occurred_at' => now()]);
    StationLog::factory()->create(['station_id' => $this->station->id, 'title' => 'Gestern Song', 'occurred_at' => now()->subDay()]);

    Livewire::test(Index::class)
        ->assertSee('Heute Song')
        ->assertDontSee('Gestern Song');
});

test('it does not show entries from other stations', function () {
    $other = Station::factory()->create(['user_id' => $this->user->id]);
    StationLog::factory()->create(['station_id' => $other->id, 'title' => 'Fremder Song', 'occurred_at' => now()]);

    Livewire::test(Index::class)->assertDontSee('Fremder Song');
});

test('the playlist filter narrows to playlist tracks', function () {
    StationLog::factory()->create(['station_id' => $this->station->id, 'source' => 'playlist', 'title' => 'Listen Song', 'occurred_at' => now()]);
    StationLog::factory()->live()->create(['station_id' => $this->station->id, 'title' => 'Live Moderation', 'occurred_at' => now()]);

    Livewire::test(Index::class)
        ->set('filter', 'playlist')
        ->assertSee('Listen Song')
        ->assertDontSee('Live Moderation');
});

test('the rundown filter shows only generation events', function () {
    StationLog::factory()->create(['station_id' => $this->station->id, 'title' => 'Some Track', 'occurred_at' => now()]);
    StationLog::factory()->create([
        'station_id' => $this->station->id,
        'event' => StationLog::EVENT_RUNDOWN_GENERATED,
        'title' => null,
        'artist' => null,
        'source' => null,
        'message' => 'Rundown 14:00 Uhr · 12 Titel',
        'occurred_at' => now(),
    ]);

    Livewire::test(Index::class)
        ->set('filter', 'rundown')
        ->assertSee('Rundown 14:00 Uhr')
        ->assertDontSee('Some Track');
});

test('the live switch filter shows start and stop events', function () {
    StationLog::factory()->create([
        'station_id' => $this->station->id,
        'event' => StationLog::EVENT_LIVE_STARTED,
        'title' => null, 'artist' => null, 'source' => null,
        'message' => 'Live-Übernahme gestartet.',
        'occurred_at' => now(),
    ]);
    StationLog::factory()->create(['station_id' => $this->station->id, 'title' => 'Normaler Titel', 'occurred_at' => now()]);

    Livewire::test(Index::class)
        ->set('filter', 'live_switch')
        ->assertSee('Live-Start')
        ->assertDontSee('Normaler Titel');
});

test('search matches title and artist', function () {
    StationLog::factory()->create(['station_id' => $this->station->id, 'title' => 'Bohemian Rhapsody', 'artist' => 'Queen', 'occurred_at' => now()]);
    StationLog::factory()->create(['station_id' => $this->station->id, 'title' => 'Imagine', 'artist' => 'Lennon', 'occurred_at' => now()]);

    Livewire::test(Index::class)
        ->set('search', 'Queen')
        ->assertSee('Bohemian Rhapsody')
        ->assertDontSee('Imagine');
});
