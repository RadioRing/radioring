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
});

/** Creates one imported file of an S4R show. */
function makeSyndicationPart(Station $station, int $sendungId, string $variant, int $part, string $show = 'Q-Burn'): ExternalSource
{
    return ExternalSource::factory()->create([
        'station_id' => $station->id,
        'name' => "{$show} #{$part} (laut.fm)",
        'broadcast_title' => $show,
        'kind' => 'syndication',
        'syndication_sendung_id' => $sendungId,
        'syndication_variant' => $variant,
        'syndication_filename' => "part_{$part}.mp3",
    ]);
}

test('the files of one imported show are folded into a group', function () {
    makeSyndicationPart($this->station, 42, 'lfm', 1);
    makeSyndicationPart($this->station, 42, 'lfm', 2);
    makeSyndicationPart($this->station, 42, 'normal', 1);
    ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'name' => 'Alpha feed',
        'kind' => 'url',
        'url' => 'https://example.com/a.mp3',
    ]);

    Livewire::test(Index::class)
        ->assertViewHas('rows', function (array $rows) {
            $summary = array_map(fn (array $row) => [$row['label'], $row['sources']->count()], $rows);

            // The single file of the other variant stays a plain row under its own name.
            return $summary === [
                ['Alpha feed', 1],
                ['Q-Burn #1 (laut.fm)', 1],
                ['Q-Burn (laut.fm)', 2],
            ];
        })
        ->assertSee('2 files');
});

test('the group key identifies show and variant', function () {
    expect(Index::groupKey(42, 'lfm'))->toBe('s4r-42-lfm')
        ->and(Index::groupKey(42, 'normal'))->not->toBe(Index::groupKey(42, 'lfm'));
});

test('search and filters are kept in the url', function () {
    Livewire::withQueryParams(['q' => 'burn', 'kind' => 'syndication', 'status' => 'error'])
        ->test(Index::class)
        ->assertSet('search', 'burn')
        ->assertSet('filterKind', 'syndication')
        ->assertSet('filterStatus', 'error');
});

test('editing opens the dialog and saving closes it and highlights the row', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'name' => 'Feed',
        'kind' => 'url',
        'url' => 'https://example.com/a.mp3',
    ]);

    Livewire::test(Index::class)
        ->call('startEdit', $source->id)
        ->assertDispatched('source-form-opened')
        ->set('name', 'Renamed feed')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSet('highlightId', $source->id)
        ->assertDispatched('source-form-closed')
        ->assertDispatched('source-row-highlighted', id: $source->id);

    expect($source->fresh()->name)->toBe('Renamed feed');
});

test('the connect dialog opens and closes', function () {
    Livewire::test(Index::class)
        ->call('startConnect')
        ->assertSet('showConnect', true)
        ->assertDispatched('s4r-connect-opened')
        ->set('s4rTokenInput', 'tok-1234567890')
        ->call('connectS4r')
        ->assertSet('showConnect', false)
        ->assertDispatched('s4r-connect-closed');

    expect($this->station->fresh()->hasSyndicationConnection())->toBeTrue();
});

test('cancelling the import wizard closes its dialog', function () {
    Livewire::test(Index::class)
        ->set('showImport', true)
        ->call('cancelImport')
        ->assertSet('showImport', false)
        ->assertDispatched('source-import-closed');
});
