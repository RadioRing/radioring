<?php

use App\Livewire\ExternalSource\Index;
use App\Models\ExternalSource;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.syndications4radio.base_url' => 'https://s4r.test/api/v1/partner']);

    $this->owner = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->owner->id]);

    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->owner);
});

test('connecting stores the partner token encrypted', function () {
    Livewire::test(Index::class)
        ->set('s4rTokenInput', 'partner-token-1234567890')
        ->call('connectS4r')
        ->assertHasNoErrors();

    $this->station->refresh();

    expect($this->station->hasSyndicationConnection())->toBeTrue()
        ->and($this->station->s4r_partner_token)->toBe('partner-token-1234567890')
        // Im Klartext darf der Token nicht in der DB stehen.
        ->and($this->station->getRawOriginal('s4r_partner_token'))->not->toBe('partner-token-1234567890');
});

test('the import wizard creates one pinned source per file of the chosen variant', function () {
    $this->station->update(['s4r_partner_token' => 'tok-1234567890']);

    Http::fake([
        'https://s4r.test/api/v1/partner/shows' => Http::response([
            'data' => [[
                'id' => 42,
                'name' => 'Q-Burn',
                'genre' => 'Electronic',
                'duration' => 60,
                'frequency' => 'Weekly',
                'is_lautfm' => true,
                'available_variants' => ['lfm', 'normal'],
            ]],
        ]),
        // Q-Burn hat drei Dateien hinter der lfm-Variante.
        'https://s4r.test/api/v1/partner/shows/42/files*' => Http::response([
            'files' => [
                ['title' => 'Q-Burn #1', 'filename' => 'qburn_1.mp3', 'duration' => 1200, 'url' => 'https://s4r.test/dl/1', 'expires_at' => now()->addHour()->toIso8601String()],
                ['title' => 'Q-Burn #2', 'filename' => 'qburn_2.mp3', 'duration' => 1180, 'url' => 'https://s4r.test/dl/2', 'expires_at' => now()->addHour()->toIso8601String()],
                ['title' => 'Q-Burn #3', 'filename' => 'qburn_3.mp3', 'duration' => 1215, 'url' => 'https://s4r.test/dl/3', 'expires_at' => now()->addHour()->toIso8601String()],
            ],
        ]),
    ]);

    Livewire::test(Index::class)
        ->call('startImport')
        ->assertSet('importStep', 1)
        ->assertSee('Q-Burn')
        ->call('selectImportShow', 42)
        ->assertSet('importStep', 2)
        ->assertSet('importVariant', 'lfm')   // laut.fm-Sendung → lfm vorbelegt
        ->call('importShow')
        ->assertHasNoErrors();

    $sources = ExternalSource::where('station_id', $this->station->id)->orderBy('id')->get();

    expect($sources)->toHaveCount(3)
        ->and($sources->pluck('syndication_filename')->all())->toBe(['qburn_1.mp3', 'qburn_2.mp3', 'qburn_3.mp3'])
        // Echte Dateilänge je Datei, nicht die gebuchten 60 min der Sendung.
        ->and($sources->pluck('expected_duration_seconds')->all())->toBe([1200, 1180, 1215])
        ->and($sources->every(fn ($s) => $s->kind === 'syndication' && $s->syndication_sendung_id === 42 && $s->syndication_variant === 'lfm'))->toBeTrue()
        ->and($sources->first()->name)->toContain('Q-Burn #1');
});

test('resolveUrl asks the partner API for the signed url of the pinned file', function () {
    $this->station->update(['s4r_partner_token' => 'tok-1234567890']);

    Http::fake([
        'https://s4r.test/api/v1/partner/shows/42/files*' => Http::response([
            'files' => [
                ['title' => 'Q-Burn #1', 'filename' => 'qburn_1.mp3', 'url' => 'https://s4r.test/dl/1?sig=a', 'expires_at' => now()->addHour()->toIso8601String()],
                ['title' => 'Q-Burn #2', 'filename' => 'qburn_2.mp3', 'url' => 'https://s4r.test/dl/2?sig=b', 'expires_at' => now()->addHour()->toIso8601String()],
                ['title' => 'Q-Burn #3', 'filename' => 'qburn_3.mp3', 'url' => 'https://s4r.test/dl/3?sig=c', 'expires_at' => now()->addHour()->toIso8601String()],
            ],
        ]),
    ]);

    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'kind' => 'syndication',
        'url' => null,
        'syndication_sendung_id' => 42,
        'syndication_variant' => 'lfm',
        'syndication_filename' => 'qburn_2.mp3',
    ]);

    // Genau die gepinnte (zweite) Datei, nicht einfach die erste.
    expect($source->resolveUrl())->toBe('https://s4r.test/dl/2?sig=b');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'variant=lfm')
        && $request->hasHeader('Authorization', 'Bearer tok-1234567890'));
});

test('resolveUrl falls back to the first file when the pinned name is gone', function () {
    $this->station->update(['s4r_partner_token' => 'tok-1234567890']);

    Http::fake([
        'https://s4r.test/api/v1/partner/shows/42/files*' => Http::response([
            'files' => [
                ['title' => 'Q-Burn #1', 'filename' => 'qburn_1.mp3', 'url' => 'https://s4r.test/dl/1?sig=a', 'expires_at' => now()->addHour()->toIso8601String()],
            ],
        ]),
    ]);

    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'kind' => 'syndication',
        'url' => null,
        'syndication_sendung_id' => 42,
        'syndication_variant' => 'lfm',
        'syndication_filename' => 'verschwunden.mp3',
    ]);

    expect($source->resolveUrl())->toBe('https://s4r.test/dl/1?sig=a');
});

test('refreshing a syndication source pulls the current file duration from the API', function () {
    $this->station->update(['s4r_partner_token' => 'tok-1234567890']);

    Http::fake([
        'https://s4r.test/api/v1/partner/shows/42/files*' => Http::response([
            'files' => [
                ['title' => 'Q-Burn #1', 'filename' => 'qburn_1.mp3', 'duration' => 1234, 'url' => 'https://s4r.test/dl/1', 'expires_at' => now()->addHour()->toIso8601String()],
            ],
        ]),
    ]);

    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'kind' => 'syndication',
        'url' => null,
        'syndication_sendung_id' => 42,
        'syndication_variant' => 'lfm',
        'syndication_filename' => 'qburn_1.mp3',
        'expected_duration_seconds' => 3600,   // veralteter Wert
    ]);

    Livewire::test(Index::class)
        ->call('refreshSyndicationDuration', $source->id)
        ->assertHasNoErrors();

    expect($source->refresh()->expected_duration_seconds)->toBe(1234);
});

test('refreshing warns when the pinned file is gone at the source', function () {
    $this->station->update(['s4r_partner_token' => 'tok-1234567890']);

    Http::fake([
        'https://s4r.test/api/v1/partner/shows/42/files*' => Http::response([
            'files' => [
                ['title' => 'Q-Burn #2', 'filename' => 'qburn_2.mp3', 'duration' => 1200, 'url' => 'https://s4r.test/dl/2', 'expires_at' => now()->addHour()->toIso8601String()],
            ],
        ]),
    ]);

    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'kind' => 'syndication',
        'url' => null,
        'syndication_sendung_id' => 42,
        'syndication_variant' => 'lfm',
        'syndication_filename' => 'qburn_1.mp3',
        'expected_duration_seconds' => 1200,
    ]);

    Livewire::test(Index::class)
        ->call('refreshSyndicationDuration', $source->id);

    // Wert bleibt unverändert, da die Datei nicht mehr gefunden wurde.
    expect($source->refresh()->expected_duration_seconds)->toBe(1200);
});

test('resolveUrl returns null when the station is not connected', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id,
        'kind' => 'syndication',
        'url' => null,
        'syndication_sendung_id' => 42,
        'syndication_variant' => 'normal',
    ]);

    expect($source->resolveUrl())->toBeNull();
});

test('startImport surfaces an error when the token is rejected', function () {
    $this->station->update(['s4r_partner_token' => 'bad-token-123456']);

    Http::fake([
        'https://s4r.test/api/v1/partner/shows' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    Livewire::test(Index::class)
        ->call('startImport')
        ->assertSet('importShows', [])
        ->assertSee('Token ungültig');
});
