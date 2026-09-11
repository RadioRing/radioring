<?php

use App\Livewire\ExternalSource\Index;
use App\Models\ExternalSource;
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

    $this->syndication = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'name' => 'Q-Burn #1 (laut.fm)',
        'broadcast_title' => 'Q-Burn',
        'kind' => 'syndication',
        'syndication_sendung_id' => 42,
        'syndication_variant' => 'lfm',
        'syndication_filename' => 'qburn_1.mp3',
    ]);

    $this->feed = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'name' => 'Morning weather',
        'kind' => 'url',
        'url' => 'https://partner.example/weather.mp3',
        'last_error' => 'HTTP 500',
    ]);

    $this->news = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'name' => 'Newscast',
        'kind' => 'news',
    ]);
});

/** @return array<int, string> the names the list currently shows */
function listedNames($component): array
{
    return $component->viewData('sources')->pluck('name')->all();
}

test('the list shows everything without filters', function () {
    $component = Livewire::test(Index::class);

    expect(listedNames($component))->toHaveCount(3)
        ->and($component->instance()->hasActiveFilters())->toBeFalse();
});

test('the search matches the name', function () {
    $component = Livewire::test(Index::class)->set('search', 'weather');

    expect(listedNames($component))->toBe(['Morning weather']);
});

test('the search matches the broadcast title, the address and the file name', function () {
    expect(listedNames(Livewire::test(Index::class)->set('search', 'Q-Burn')))->toBe(['Q-Burn #1 (laut.fm)'])
        ->and(listedNames(Livewire::test(Index::class)->set('search', 'partner.example')))->toBe(['Morning weather'])
        ->and(listedNames(Livewire::test(Index::class)->set('search', 'qburn_1')))->toBe(['Q-Burn #1 (laut.fm)']);
});

test('a numeric search also finds the S4R show id', function () {
    $component = Livewire::test(Index::class)->set('search', '42');

    expect(listedNames($component))->toBe(['Q-Burn #1 (laut.fm)']);
});

test('the kind filter narrows the list', function () {
    expect(listedNames(Livewire::test(Index::class)->set('filterKind', 'syndication')))->toBe(['Q-Burn #1 (laut.fm)'])
        ->and(listedNames(Livewire::test(Index::class)->set('filterKind', 'news')))->toBe(['Newscast']);
});

test('the status filter finds sources with an error', function () {
    $component = Livewire::test(Index::class)->set('filterStatus', 'error');

    expect(listedNames($component))->toBe(['Morning weather']);
});

test('the status filter separates used from unused sources', function () {
    $playlist = $this->station->playlists()->create(['name' => 'P', 'playback_mode' => 'sequential']);
    $playlist->items()->create([
        'position' => 0, 'type' => 'external', 'title' => 'Newscast', 'external_source_id' => $this->news->id,
    ]);

    expect(listedNames(Livewire::test(Index::class)->set('filterStatus', 'used')))->toBe(['Newscast'])
        ->and(listedNames(Livewire::test(Index::class)->set('filterStatus', 'unused')))
        ->toBe(['Morning weather', 'Q-Burn #1 (laut.fm)']);
});

test('search and filter narrow together instead of widening', function () {
    // Ohne Klammerung um die Suchbedingungen wuerde das orWhere den Art-Filter aufheben.
    $component = Livewire::test(Index::class)
        ->set('filterKind', 'syndication')
        ->set('search', 'weather');

    expect(listedNames($component))->toBe([]);
});

test('the filters can be reset', function () {
    $component = Livewire::test(Index::class)
        ->set('search', 'weather')
        ->set('filterKind', 'url')
        ->set('filterStatus', 'error')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('filterKind', '')
        ->assertSet('filterStatus', '');

    expect(listedNames($component))->toHaveCount(3);
});

test('a source of another station never shows up', function () {
    $otherStation = Station::factory()->create();
    ExternalSource::factory()->create([
        'station_id' => $otherStation->id, 'name' => 'Foreign weather', 'kind' => 'url',
    ]);

    $component = Livewire::test(Index::class)->set('search', 'weather');

    expect(listedNames($component))->toBe(['Morning weather']);
});
