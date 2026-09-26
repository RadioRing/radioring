<?php

use App\Livewire\Playlist\Index;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

test('duplicating a playlist copies it with all its items', function () {
    $playlist = $this->station->playlists()->create([
        'name' => 'Morning Show', 'playback_mode' => 'random',
    ]);
    $playlist->items()->create(['position' => 0, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => 0, 'fixed_mode' => 'hard']);
    $playlist->items()->create(['position' => 1, 'type' => 'fill', 'title' => 'Auffüllen', 'fill_tags' => [1, 2]]);
    $playlist->items()->create(['position' => 2, 'type' => 'adbreak', 'title' => 'Werbung']);

    Livewire::test(Index::class)
        ->call('duplicate', $playlist->id)
        ->assertHasNoErrors();

    $copy = $this->station->playlists()->where('name', 'Morning Show (Kopie)')->first();

    expect($copy)->not->toBeNull()
        ->and($copy->id)->not->toBe($playlist->id)
        ->and($copy->playback_mode)->toBe('random')
        ->and($copy->startsHard())->toBeTrue()
        ->and($copy->items()->count())->toBe(3);

    $items = $copy->items()->orderBy('position')->get();
    expect($items[0]->isHardMarker())->toBeTrue()
        ->and($items[1]->type)->toBe('fill')
        ->and($items[1]->fill_tags)->toBe([1, 2])
        ->and($items[2]->type)->toBe('adbreak');

    // Original bleibt unverändert.
    expect($playlist->items()->count())->toBe(3);
});

test('duplicating only touches playlists of the current station', function () {
    $foreign = Station::factory()->create();
    $playlist = $foreign->playlists()->create([
        'name' => 'Fremd', 'playback_mode' => 'sequential',
    ]);

    expect(fn () => Livewire::test(Index::class)->call('duplicate', $playlist->id))
        ->toThrow(ModelNotFoundException::class);

    expect($foreign->playlists()->count())->toBe(1); // keine Kopie angelegt
});
