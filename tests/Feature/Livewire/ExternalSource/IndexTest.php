<?php

use App\Livewire\ExternalSource\Index;
use App\Models\ExternalSource;
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

test('user can create a url source', function () {
    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('name', 'Morgenshow')
        ->set('kind', 'url')
        ->set('url', 'https://example.com/show.mp3')
        ->set('prefetchLead', 240)
        ->call('save')
        ->assertHasNoErrors();

    $source = $this->station->externalSources()->first();
    expect($source->name)->toBe('Morgenshow')
        ->and($source->kind)->toBe('url')
        ->and($source->url)->toBe('https://example.com/show.mp3')
        ->and($source->prefetch_lead_seconds)->toBe(240);
});

test('a url source requires a url', function () {
    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('name', 'Ohne URL')
        ->set('kind', 'url')
        ->set('url', '')
        ->call('save')
        ->assertHasErrors(['url']);
});

test('a news source does not store a url', function () {
    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('name', 'Nachrichten')
        ->set('kind', 'news')
        ->set('url', 'https://ignored.example')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->station->externalSources()->first()->url)->toBeNull();
});

test('user can edit a source', function () {
    $source = ExternalSource::factory()->create(['station_id' => $this->station->id, 'name' => 'Alt']);

    Livewire::test(Index::class)
        ->call('startEdit', $source->id)
        ->assertSet('name', 'Alt')
        ->set('name', 'Neu')
        ->call('save')
        ->assertHasNoErrors();

    expect($source->fresh()->name)->toBe('Neu');
});

test('user can delete a source', function () {
    $source = ExternalSource::factory()->create(['station_id' => $this->station->id]);

    Livewire::test(Index::class)->call('delete', $source->id);

    expect(ExternalSource::find($source->id))->toBeNull();
});

test('a user cannot edit a source of another station', function () {
    $foreign = ExternalSource::factory()->create();

    Livewire::test(Index::class)->call('startEdit', $foreign->id);
})->throws(ModelNotFoundException::class);

test('user can create a source with trim and fade-in enabled', function () {
    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('name', 'laut.fm News')
        ->set('kind', 'news')
        ->set('trimLeadingSilence', true)
        ->set('fadeIn', true)
        ->call('save')
        ->assertHasNoErrors();

    $source = $this->station->externalSources()->first();
    expect($source->trim_leading_silence)->toBeTrue()
        ->and($source->fade_in)->toBeTrue();
});

test('editing loads the transition flags into the form', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'trim_leading_silence' => true,
        'fade_in' => false,
    ]);

    Livewire::test(Index::class)
        ->call('startEdit', $source->id)
        ->assertSet('trimLeadingSilence', true)
        ->assertSet('fadeIn', false);
});
