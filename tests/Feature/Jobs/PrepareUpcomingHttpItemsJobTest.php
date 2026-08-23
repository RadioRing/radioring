<?php

use App\Jobs\PrepareUpcomingHttpItemsJob;
use App\Models\ExternalSource;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use App\Services\AudioMetadataService;
use App\Services\LoudnessAnalyzerService;
use App\Services\SilenceTrimmerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->station = Station::factory()->create();
    $this->station->stream()->create(['container_name' => 'radioring-'.$this->station->slug, 'status' => 'running']);
    $this->travelTo(today()->setHour(10)->setMinute(0));
});

function externalItem(Station $station, ExternalSource $source, string $airAt): GeneratedPlaylistItem
{
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 10,
        'status' => 'ready',
    ]);

    return GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'external_source_id' => $source->id,
        'position' => 0,
        'source_type' => 'external',
        'title' => $source->name,
        'duration_seconds' => 300,
        'absolute_broadcast_at' => today()->setTimeFromTimeString($airAt),
    ]);
}

function fakeLoudnormResult(string $inputI, string $inputTp): void
{
    $json = "[Parsed_loudnorm_0 @ 0x0]\n{\n\t\"input_i\" : \"{$inputI}\",\n\t\"input_tp\" : \"{$inputTp}\",\n\t\"input_lra\" : \"7.0\",\n\t\"input_thresh\" : \"-30.0\"\n}";
    Process::fake(['*' => Process::result(output: '', errorOutput: $json)]);
}

test('prepares a due external item: downloads, measures and caches it', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/syndi.mp3', 'prefetch_lead_seconds' => 180, 'normalize' => true,
    ]);
    // Sendezeit in 60 s → innerhalb des 180-s-Vorlaufs.
    $item = externalItem($this->station, $source, '10:01:00');

    Http::fake(['example.com/*' => Http::response('AUDIO-BYTES', 200)]);
    fakeLoudnormResult('-20.0', '-5.0');

    (new PrepareUpcomingHttpItemsJob)->handle(app(LoudnessAnalyzerService::class), app(SilenceTrimmerService::class), app(AudioMetadataService::class));

    $item->refresh();
    expect($item->prepared_path)->not->toBeNull()
        ->and($item->loudness_lufs)->toBe(-20.0)
        ->and($item->prepared_at)->not->toBeNull();
    Storage::disk('local')->assertExists($item->prepared_path);

    expect($source->fresh()->last_error)->toBeNull()
        ->and($source->fresh()->last_loudness_lufs)->toBe(-20.0);
});

test('updates the source expected duration from the prepared file length', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/syndi.mp3', 'normalize' => false,
        'expected_duration_seconds' => 3600,   // veralteter Wert
    ]);
    $item = externalItem($this->station, $source, '10:01:00');

    Http::fake(['example.com/*' => Http::response('AUDIO', 200)]);
    Process::fake();

    // Echte Dauermessung der vorbereiteten Datei simulieren.
    $metadata = Mockery::mock(AudioMetadataService::class);
    $metadata->shouldReceive('read')->once()->andReturn([
        'title' => null, 'artist' => null, 'album' => null, 'duration' => 1234,
    ]);

    (new PrepareUpcomingHttpItemsJob)->handle(app(LoudnessAnalyzerService::class), app(SilenceTrimmerService::class), $metadata);

    expect($item->fresh()->prepared_path)->not->toBeNull()
        ->and($source->fresh()->expected_duration_seconds)->toBe(1234);
});

test('records an error and leaves the item unprepared on a failed download', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'https://example.com/down.mp3',
    ]);
    $item = externalItem($this->station, $source, '10:01:00');

    Http::fake(['example.com/*' => Http::response('', 503)]);

    (new PrepareUpcomingHttpItemsJob)->handle(app(LoudnessAnalyzerService::class), app(SilenceTrimmerService::class), app(AudioMetadataService::class));

    expect($item->fresh()->prepared_path)->toBeNull()
        ->and($source->fresh()->last_error)->toContain('503');
});

test('does not prepare an item that is still beyond the prefetch lead', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/late.mp3', 'prefetch_lead_seconds' => 120,
    ]);
    // Sendezeit erst in 10 min → außerhalb des 2-min-Vorlaufs.
    $item = externalItem($this->station, $source, '10:10:00');

    Http::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(LoudnessAnalyzerService::class), app(SilenceTrimmerService::class), app(AudioMetadataService::class));

    Http::assertNothingSent();
    expect($item->fresh()->prepared_path)->toBeNull();
});

test('runs an ffmpeg silenceremove pass when trim_leading_silence is enabled', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/news.mp3', 'normalize' => false,
        'trim_leading_silence' => true,
    ]);
    $item = externalItem($this->station, $source, '10:01:00');

    Http::fake(['example.com/*' => Http::response('AUDIO', 200)]);
    Process::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(LoudnessAnalyzerService::class), app(SilenceTrimmerService::class), app(AudioMetadataService::class));

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'silenceremove'));
    expect($item->fresh()->prepared_path)->not->toBeNull();
});

test('does not run a trim pass when trim_leading_silence is disabled', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/raw.mp3', 'normalize' => false,
        'trim_leading_silence' => false,
    ]);
    externalItem($this->station, $source, '10:01:00');

    Http::fake(['example.com/*' => Http::response('AUDIO', 200)]);
    Process::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(LoudnessAnalyzerService::class), app(SilenceTrimmerService::class), app(AudioMetadataService::class));

    Process::assertNothingRan();
});

test('skips a normalize=false source without measuring loudness', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/raw.mp3', 'normalize' => false,
    ]);
    $item = externalItem($this->station, $source, '10:01:00');

    Http::fake(['example.com/*' => Http::response('AUDIO', 200)]);
    Process::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(LoudnessAnalyzerService::class), app(SilenceTrimmerService::class), app(AudioMetadataService::class));

    Process::assertNothingRan();
    expect($item->fresh()->prepared_path)->not->toBeNull()
        ->and($item->fresh()->loudness_lufs)->toBeNull();
});
