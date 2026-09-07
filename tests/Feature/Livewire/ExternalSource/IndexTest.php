<?php

use App\Livewire\ExternalSource\Index;
use App\Models\ExternalSource;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

test('a url source accepts ftp and ftps addresses', function (string $url) {
    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('name', 'Zulieferung')
        ->set('kind', 'url')
        ->set('url', $url)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->station->externalSources()->first()->url)->toBe($url);
})->with([
    'ftp' => 'ftp://files.example.com/show.mp3',
    'ftps' => 'ftps://files.example.com/show.mp3',
]);

test('a url source rejects a scheme the fetcher cannot download', function (string $url) {
    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('name', 'Falsches Schema')
        ->set('kind', 'url')
        ->set('url', $url)
        ->call('save')
        ->assertHasErrors(['url']);

    expect($this->station->externalSources()->count())->toBe(0);
})->with([
    'sftp' => 'sftp://files.example.com/show.mp3',
    'file' => 'file:///etc/passwd',
    'nonsense' => 'files.example.com/show.mp3',
]);

test('url credentials are stored encrypted', function () {
    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('name', 'FTP-Zulieferung')
        ->set('kind', 'url')
        ->set('url', 'ftps://files.example.com/show.mp3')
        ->set('urlUsername', 'radioring')
        ->set('urlPassword', 'geheim123')
        ->call('save')
        ->assertHasNoErrors();

    $source = $this->station->externalSources()->first();
    expect($source->url_username)->toBe('radioring')
        ->and($source->url_password)->toBe('geheim123');

    $raw = DB::table('external_sources')->where('id', $source->id)->first();
    expect($raw->url_password)->not->toBe('geheim123')
        ->and($raw->url_username)->not->toBe('radioring');
});

test('an empty password field keeps the stored password', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'kind' => 'url',
        'url' => 'ftp://files.example.com/show.mp3',
        'url_username' => 'radioring',
        'url_password' => 'geheim123',
    ]);

    Livewire::test(Index::class)
        ->call('startEdit', $source->id)
        // The plaintext is never rendered into the form, so the field starts blank.
        ->assertSet('urlPassword', '')
        ->assertSet('urlUsername', 'radioring')
        ->set('urlUsername', 'radioring2')
        ->call('save')
        ->assertHasNoErrors();

    expect($source->fresh()->url_password)->toBe('geheim123')
        ->and($source->fresh()->url_username)->toBe('radioring2');
});

test('switching a source away from kind=url clears its credentials', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'kind' => 'url',
        'url' => 'ftp://files.example.com/show.mp3',
        'url_username' => 'radioring',
        'url_password' => 'geheim123',
    ]);

    Livewire::test(Index::class)
        ->call('startEdit', $source->id)
        ->set('kind', 'news')
        ->call('save')
        ->assertHasNoErrors();

    $source->refresh();
    expect($source->url)->toBeNull()
        ->and($source->url_username)->toBeNull()
        ->and($source->url_password)->toBeNull();
});

test('an ftp address with spaces in the path is accepted and stored encoded', function () {
    Livewire::test(Index::class)
        ->call('startCreate')
        ->set('name', 'Stafford')
        ->set('kind', 'url')
        ->set('url', 'ftp://markstafford.co.uk/All/current/Show - Stafford 1A.mp3')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->station->externalSources()->first()->url)
        ->toBe('ftp://markstafford.co.uk/All/current/Show%20-%20Stafford%201A.mp3');
});
