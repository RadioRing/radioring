<?php

use App\Livewire\Playlist\Manager;
use App\Models\ExternalSource;
use App\Models\Playlist;
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
    $this->playlist = $this->station->playlists()->create([
        'name' => 'Test Playlist',
        'playback_mode' => 'sequential',
    ]);
    $this->actingAs($this->user);
});

function makeMedia(string $title, string $type = 'music', ?string $artist = null, int $duration = 180)
{
    return test()->station->mediaFiles()->create([
        'title' => $title,
        'artist' => $artist,
        'type' => $type,
        'file_path' => 'tenants/test/media/'.str($title)->slug().'.mp3',
        'duration_seconds' => $duration,
    ]);
}

test('the palette starts on the media tab and lists the library', function () {
    makeMedia('Sunrise');
    makeMedia('Station Jingle', 'jingle');

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->assertSet('paletteTab', 'media')
        ->assertViewHas('paletteEntries', fn ($entries) => $entries->pluck('title')->all() === ['Station Jingle', 'Sunrise']);
});

test('the palette search also matches the artist', function () {
    makeMedia('Sunrise', 'music', 'The Nightingales');
    makeMedia('Moonlight', 'music', 'Someone Else');

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('paletteSearch', 'nightingale')
        ->assertViewHas('paletteEntries', fn ($entries) => $entries->pluck('title')->all() === ['Sunrise']);
});

test('the palette shows containers, external sources and special elements per tab', function () {
    $container = $this->station->playlists()->create([
        'name' => 'News block', 'kind' => Playlist::KIND_CONTAINER, 'playback_mode' => 'sequential',
    ]);
    ExternalSource::factory()->create(['station_id' => $this->station->id, 'name' => 'Syndication']);

    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist]);

    $component->call('switchTab', 'container')
        ->assertViewHas('paletteEntries', fn ($entries) => $entries->first()->key() === 'container:'.$container->id);

    $component->call('switchTab', 'external')
        ->assertViewHas('paletteEntries', fn ($entries) => $entries->first()->title === 'Syndication');

    $component->call('switchTab', 'special')
        ->assertViewHas('paletteEntries', fn ($entries) => $entries->pluck('id')->all() === ['fill', 'random', 'adbreak']);
});

test('picks keep their order across the tabs', function () {
    $song = makeMedia('Sunrise');
    $source = ExternalSource::factory()->create(['station_id' => $this->station->id, 'name' => 'News']);

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('togglePick', 'special:adbreak')
        ->call('togglePick', 'external:'.$source->id)
        ->call('togglePick', 'media:'.$song->id)
        ->assertSet('picks', ['special:adbreak', 'external:'.$source->id, 'media:'.$song->id])
        ->call('insertPicks')
        ->assertSet('picks', []);

    expect($this->playlist->items()->orderBy('position')->pluck('type')->all())
        ->toBe(['adbreak', 'external', 'music'])
        ->and($this->playlist->items()->orderBy('position')->pluck('position')->all())
        ->toBe([0, 1, 2]);
});

test('an entry can be dropped into the middle of the playlist', function () {
    $first = makeMedia('First');
    $second = makeMedia('Second');
    $dropped = makeMedia('Dropped');

    $component = Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'media:'.$first->id)
        ->call('insertEntry', 'media:'.$second->id);

    $component->call('insertEntryAt', 'media:'.$dropped->id, 1);

    expect($this->playlist->items()->orderBy('position')->pluck('title')->all())
        ->toBe(['First', 'Dropped', 'Second'])
        ->and($this->playlist->items()->orderBy('position')->pluck('position')->all())
        ->toBe([0, 1, 2]);
});

test('dropping at the end appends', function () {
    $first = makeMedia('First');
    $second = makeMedia('Second');

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'media:'.$first->id)
        ->call('insertEntryAt', 'media:'.$second->id, 5);

    expect($this->playlist->items()->orderBy('position')->pluck('title')->all())
        ->toBe(['First', 'Second']);
});

test('the palette pages through a large library', function () {
    foreach (range(1, 45) as $number) {
        makeMedia(sprintf('Track %02d', $number));
    }

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->assertViewHas('paletteEntries', fn ($entries) => $entries->count() === 40)
        ->assertViewHas('hasMoreEntries', true)
        ->call('loadMore')
        ->assertViewHas('paletteEntries', fn ($entries) => $entries->count() === 45)
        ->assertViewHas('hasMoreEntries', false);
});

test('switching the tab resets paging and any open form', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('loadMore')
        ->set('paletteForm', 'upload')
        ->call('switchTab', 'special')
        ->assertSet('paletteLimit', 40)
        ->assertSet('paletteForm', '');
});

test('searching resets paging', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('loadMore')
        ->assertSet('paletteLimit', 80)
        ->set('paletteSearch', 'anything')
        ->assertSet('paletteLimit', 40);
});

test('a media file of another station cannot be inserted', function () {
    $otherStation = Station::factory()->create();
    $foreign = $otherStation->mediaFiles()->create([
        'title' => 'Foreign', 'type' => 'music', 'file_path' => 'tenants/other/media/foreign.mp3',
    ]);

    expect(fn () => Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->call('insertEntry', 'media:'.$foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect($this->playlist->items()->count())->toBe(0);
});

test('a url element is added through its own form', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('paletteForm', 'url')
        ->set('urlTitle', 'Partner stream')
        ->set('urlAddress', 'https://example.org/stream.mp3')
        ->set('urlDuration', '600')
        ->call('submitUrl')
        ->assertHasNoErrors()
        ->assertSet('paletteForm', '');

    $item = $this->playlist->items()->first();

    expect($item->type)->toBe('url')
        ->and($item->url)->toBe('https://example.org/stream.mp3')
        ->and($item->duration_seconds)->toBe(600);
});

test('the url form insists on a valid address', function () {
    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('paletteForm', 'url')
        ->set('urlTitle', 'Broken')
        ->set('urlAddress', 'not-a-url')
        ->call('submitUrl')
        ->assertHasErrors(['urlAddress']);

    expect($this->playlist->items()->count())->toBe(0);
});
