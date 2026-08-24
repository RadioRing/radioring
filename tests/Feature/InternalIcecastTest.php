<?php

use App\Livewire\Output\Index;
use App\Models\Station;
use App\Models\StationOutput;
use App\Models\User;
use App\Services\LiquidsoapScriptGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'radioring.stream.domain' => 'stream.example.com',
        'radioring.icecast.traefik_enabled' => true,
    ]);

    $this->user = User::factory()->create();
    $this->station = Station::factory()->create([
        'user_id' => $this->user->id,
        'slug' => 'mysender',
    ]);

    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

// ── Availability ─────────────────────────────────────────────────────────────

test('the internal server needs both a stream domain and a reverse proxy', function (?string $domain, bool $traefik, bool $expected) {
    config([
        'radioring.stream.domain' => $domain,
        'radioring.icecast.traefik_enabled' => $traefik,
    ]);

    expect(Station::internalStreamSupported())->toBe($expected);
})->with([
    'both present' => ['stream.example.com', true, true],
    'no proxy' => ['stream.example.com', false, false],
    'no domain' => ['', true, false],
    'neither' => ['', false, false],
]);

test('the public url is the station subdomain plus the mount', function () {
    $output = $this->station->outputs()->create([
        'type' => 'internal',
        'host' => $this->station->icecastContainerName(),
        'port' => 8000,
        'mount' => '/stream',
        'username' => 'source',
        'bitrate' => 128,
        'enabled' => true,
    ]);

    expect($this->station->internalStreamUrl($output))
        ->toBe('https://mysender.stream.example.com/stream');
});

test('without a reverse proxy there is no public url to show', function () {
    config(['radioring.icecast.traefik_enabled' => false]);

    $output = $this->station->outputs()->create([
        'type' => 'internal',
        'host' => 'x',
        'port' => 8000,
        'mount' => '/stream',
        'username' => 'source',
        'bitrate' => 128,
        'enabled' => true,
    ]);

    expect($this->station->internalStreamUrl($output))->toBeNull();
});

// ── Credentials ──────────────────────────────────────────────────────────────

test('ensureStream provisions an icecast source password and keeps it stable', function () {
    $password = $this->station->ensureStream()->icecast_password;

    expect($password)->not->toBeNull();
    expect($this->station->fresh()->ensureStream()->icecast_password)->toBe($password);
});

test('the icecast source password is stored encrypted', function () {
    $stream = $this->station->ensureStream();

    expect($stream->getRawOriginal('icecast_password_enc'))
        ->not->toContain($stream->icecast_password);
});

// ── Script generation ────────────────────────────────────────────────────────

test('an internal output targets the sidecar container, not the public address', function () {
    $this->station->outputs()->create([
        'type' => 'internal',
        'host' => 'ignored-on-purpose',
        'port' => 1234,
        'mount' => '/stream',
        'username' => 'ignored',
        'bitrate' => 192,
        'enabled' => true,
    ]);

    $script = app(LiquidsoapScriptGenerator::class)->generate($this->station->fresh());
    $password = $this->station->ensureStream()->icecast_password;

    expect($script)
        ->toContain('host="radioring-icecast-mysender"')
        ->toContain('port=8000')
        ->toContain('mount="/stream"')
        ->toContain('%mp3(bitrate=192)')
        ->toContain('password="'.$password.'"')
        // Neither the stored display values nor the public address belong in the output:
        // sending happens strictly between containers.
        ->not->toContain('ignored-on-purpose')
        ->not->toContain('mysender.stream.example.com');
});

test('an external output is still rendered from its stored credentials', function () {
    $this->station->outputs()->create([
        'type' => 'lautfm',
        'host' => 'stream.laut.fm',
        'port' => 8080,
        'mount' => '/mysender',
        'username' => 'source',
        'password' => 'secret-pw',
        'bitrate' => 128,
        'enabled' => true,
    ]);

    expect(app(LiquidsoapScriptGenerator::class)->generate($this->station->fresh()))
        ->toContain('host="stream.laut.fm"')
        ->toContain('password="secret-pw"');
});

// ── User interface ───────────────────────────────────────────────────────────

test('an internal output is saved without the user entering any credentials', function () {
    Livewire::test(Index::class)
        ->call('createNew')
        ->set('type', 'internal')
        ->set('mount', 'stream')
        ->set('bitrate', 128)
        ->call('save')
        ->assertHasNoErrors();

    $output = StationOutput::where('station_id', $this->station->id)->firstOrFail();

    expect($output->type)->toBe('internal');
    expect($output->host)->toBe('radioring-icecast-mysender');
    expect($output->port)->toBe(8000);
    expect($output->mount)->toBe('/stream');
    expect($output->isInternal())->toBeTrue();
});

test('switching to the internal server prefills a usable mount', function () {
    Livewire::test(Index::class)
        ->call('createNew')
        ->set('type', 'internal')
        ->assertSet('mount', 'stream');
});

test('a mount with slashes or spaces is rejected for the internal server', function (string $mount) {
    Livewire::test(Index::class)
        ->call('createNew')
        ->set('type', 'internal')
        ->set('mount', $mount)
        ->call('save')
        ->assertHasErrors('mount');
})->with([
    'path traversal' => ['../admin'],
    'nested path' => ['a/b'],
    'whitespace' => ['my stream'],
    'query' => ['stream?x=1'],
]);

test('the internal server is not offered when the installation cannot provide it', function () {
    config(['radioring.icecast.traefik_enabled' => false]);

    Livewire::test(Index::class)
        ->call('createNew')
        ->assertDontSee('Internal server (RadioRing)');
});

test('an internal output cannot be forced in when the installation cannot provide it', function () {
    config(['radioring.icecast.traefik_enabled' => false]);

    Livewire::test(Index::class)
        ->call('createNew')
        ->set('type', 'internal')
        ->set('mount', 'stream')
        ->call('save')
        ->assertForbidden();

    expect(StationOutput::where('station_id', $this->station->id)->count())->toBe(0);
});
