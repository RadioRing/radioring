<?php

use App\Livewire\Admin\Settings;
use App\Models\ExternalSource;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StationLog;
use App\Models\User;
use App\Services\Telemetry\TelemetryReport;
use App\Services\Telemetry\TelemetrySender;
use App\Services\Telemetry\TelemetrySettings;
use App\Support\TelemetryBucket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Setting::flushMemo();
    config(['radioring.telemetry.endpoint' => 'https://radioring.test/api/telemetry']);
    Http::preventStrayRequests();
});

test('counts are reported as ranges, never exact', function (int $count, string $bucket) {
    expect(TelemetryBucket::for($count))->toBe($bucket);
})->with([
    [0, '0'],
    [1, '1-10'],
    [10, '1-10'],
    [11, '11-100'],
    [4711, '1001-10000'],
    [10001, '10001+'],
]);

test('the report describes each station without identifying it', function () {
    $station = Station::factory()->create(['name' => 'Radio Geheim', 's4r_partner_token' => 'secret-token']);
    $station->outputs()->create(['type' => 'lautfm', 'host' => 'stream.laut.fm', 'mount' => '/geheim', 'enabled' => true]);
    $station->outputs()->create(['type' => 'icecast', 'host' => 'icecast.example.com', 'mount' => '/live', 'enabled' => false]);
    ExternalSource::factory()->news()->create(['station_id' => $station->id]);
    MediaFile::factory()->count(12)->create(['tenant_id' => $station->tenant_id]);

    $playlist = Playlist::factory()->create(['station_id' => $station->id, 'name' => 'Morning']);
    $playlist->items()->create(['position' => 0, 'type' => 'marker', 'fixed_mode' => 'hard', 'title' => 'Top of hour']);

    StationLog::factory()->create(['station_id' => $station->id, 'event' => StationLog::EVENT_LIVE_STARTED, 'occurred_at' => now()->subDays(3)]);

    Station::factory()->create();

    $report = TelemetryReport::build('11111111-2222-3333-4444-555555555555');

    expect($report['schema'])->toBe(1)
        ->and($report['instance_id'])->toBe('11111111-2222-3333-4444-555555555555')
        ->and($report['stations'])->toHaveCount(2);

    $described = collect($report['stations'])->firstWhere('output_lautfm', true);

    expect($described)->toMatchArray([
        'media_files' => '11-100',
        'playlists' => '1-10',
        'output_lautfm' => true,
        'output_icecast' => false,
        'syndication' => true,
        'news_weather' => true,
        'url_sources' => false,
        'markers' => true,
        'hard_fixed_times' => true,
        'live_input' => true,
    ]);

    $json = json_encode($report);

    expect($json)->not->toContain('Geheim')
        ->and($json)->not->toContain('geheim')
        ->and($json)->not->toContain('secret-token')
        ->and($json)->not->toContain('example.com')
        ->and($json)->not->toContain($station->slug);
});

test('nothing is sent while telemetry is off', function () {
    Http::fake();

    expect(app(TelemetrySender::class)->isDue())->toBeFalse()
        ->and(app(TelemetrySender::class)->send())->toBeFalse();

    $this->artisan('radioring:send-telemetry --force')->assertSuccessful();

    Http::assertNothingSent();
});

test('an opted-in instance reports once a day', function () {
    Http::fake(['radioring.test/*' => Http::response(status: 204)]);
    TelemetrySettings::enable();

    $this->artisan('radioring:send-telemetry')->assertSuccessful();
    $this->artisan('radioring:send-telemetry')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['instance_id'] === TelemetrySettings::instanceId());

    $this->travel(24)->hours();
    $this->artisan('radioring:send-telemetry')->assertSuccessful();

    Http::assertSentCount(2);
});

test('a failed report is retried on the next run', function () {
    Http::fake(['radioring.test/*' => Http::response(status: 503)]);
    TelemetrySettings::enable();

    expect(app(TelemetrySender::class)->send())->toBeFalse()
        ->and(TelemetrySettings::lastSentAt())->toBeNull()
        ->and(app(TelemetrySender::class)->isDue())->toBeTrue();
});

test('an empty endpoint removes the option', function () {
    config(['radioring.telemetry.endpoint' => '']);
    TelemetrySettings::enable();

    expect(app(TelemetrySender::class)->isDue())->toBeFalse();

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test(Settings::class)
        ->assertDontSee(__('Anonymous usage statistics'));
});

test('the admin opts in, sees the payload and opts out again', function () {
    Http::fake(['radioring.test/*' => Http::response(status: 204)]);
    Station::factory()->create();

    $component = Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test(Settings::class)
        ->assertSee(__('Anonymous usage statistics'))
        ->set('showTelemetryPreview', true)
        ->assertSee('&quot;output_lautfm&quot;', false)
        ->call('enableTelemetry');

    $instanceId = TelemetrySettings::instanceId();

    expect(TelemetrySettings::enabled())->toBeTrue()
        ->and($instanceId)->not->toBeNull()
        ->and(TelemetrySettings::lastSentAt())->not->toBeNull();
    Http::assertSentCount(1);

    $component->call('resetTelemetryId');

    expect(TelemetrySettings::instanceId())->not->toBe($instanceId);

    $component->call('disableTelemetry');

    expect(TelemetrySettings::enabled())->toBeFalse()
        ->and(TelemetrySettings::instanceId())->toBeNull()
        ->and(TelemetrySettings::lastSentAt())->toBeNull();
});
