<?php

use App\Livewire\Dashboard;
use App\Models\LiquidsoapState;
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
});

test('player shows the running track while it is within its duration', function () {
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'now_playing_title' => 'Laufender Song',
        'now_playing_source_type' => 'music',
        'now_playing_duration_seconds' => 180,
        'now_playing_started_at' => now()->subSeconds(30),
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Laufender Song')
        ->assertDontSee('Kein Track aktiv');
});

test('player goes idle when the track is long past its duration (silence)', function () {
    // Track lief vor Stunden an, Dauer 30 min – längst beendet, kein neuer Callback.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'now_playing_title' => 'Hängengebliebener Song',
        'now_playing_source_type' => 'music',
        'now_playing_duration_seconds' => 1832,
        'now_playing_started_at' => now()->subHours(5),
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Kein Track aktiv')
        ->assertDontSee('Hängengebliebener Song');
});

test('player is suppressed while a live takeover is active', function () {
    // Während live darf der Programm-Player nicht „virtuell" weiterlaufen – der
    // Live-Kasten übernimmt die Anzeige.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'now_playing_title' => 'Programm-Song',
        'now_playing_source_type' => 'music',
        'now_playing_duration_seconds' => 180,
        'now_playing_started_at' => now()->subSeconds(30),
        'live_active' => true,
        'live_started_at' => now()->subSeconds(30),
    ]);

    Livewire::test(Dashboard::class)
        ->assertDontSee('Programm-Song')
        ->assertSee('Live broadcast running')
        ->assertDontSee('Kein Track aktiv');
});

test('the station counts as on air while a live takeover is active', function () {
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'live_active' => true,
        'live_started_at' => now()->subSeconds(30),
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('ON AIR - LIVE')
        ->assertDontSee('OFF AIR')
        ->assertDontSee('NO PLAYOUT');
});

test('a running container without playout is shown as no playout, not off air', function () {
    $this->station->stream()->create([
        'container_name' => 'radioring-station-'.$this->station->id,
        'status' => 'running',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('NO PLAYOUT')
        ->assertDontSee('OFF AIR');
});

test('a stopped container is shown as off air', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('OFF AIR')
        ->assertDontSee('NO PLAYOUT');
});

test('player keeps showing an adbreak past its signal duration', function () {
    // Adbreaks haben auf laut.fm eine variable Echtdauer → nicht als beendet werten.
    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'now_playing_title' => 'Werbeblock',
        'now_playing_source_type' => 'adbreak',
        'now_playing_duration_seconds' => 5,
        'now_playing_started_at' => now()->subSeconds(120),
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Werbeblock')
        ->assertDontSee('Kein Track aktiv');
});
