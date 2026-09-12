<?php

use App\Jobs\PrepareUpcomingHttpItemsJob;
use App\Models\ExternalSource;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Services\AudioMetadataService;
use App\Services\ExternalItemPreparer;
use App\Services\LiquidsoapStateService;
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

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

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
    $this->mock(AudioMetadataService::class)
        ->shouldReceive('read')->once()->andReturn([
            'title' => null, 'artist' => null, 'album' => null, 'duration' => 1234,
        ]);

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    expect($item->fresh()->prepared_path)->not->toBeNull()
        ->and($source->fresh()->expected_duration_seconds)->toBe(1234);
});

test('records an error and leaves the item unprepared on a failed download', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'https://example.com/down.mp3',
    ]);
    $item = externalItem($this->station, $source, '10:01:00');

    Http::fake(['example.com/*' => Http::response('', 503)]);

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    expect($item->fresh()->prepared_path)->toBeNull()
        ->and($item->fresh()->prepare_attempts)->toBe(1)
        ->and($source->fresh()->last_error)->toContain('503');
});

test('backs a failed item off instead of retrying it on every run', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'https://example.com/down.mp3',
        'prefetch_lead_seconds' => 1800,
    ]);
    $item = externalItem($this->station, $source, '10:25:00');

    Http::fake(['example.com/*' => Http::response('', 503)]);

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));
    $this->travel(30)->seconds();
    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    Http::assertSentCount(1);

    // Past the wait of the first failed attempt: tried again.
    $this->travel(60)->seconds();
    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    Http::assertSentCount(2);
    expect($item->fresh()->prepare_attempts)->toBe(2);
});

test('does not prepare an item that is still beyond the prefetch lead', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/late.mp3', 'prefetch_lead_seconds' => 120,
    ]);
    // Sendezeit erst in 10 min → außerhalb des 2-min-Vorlaufs.
    $item = externalItem($this->station, $source, '10:10:00');

    Http::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

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

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

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

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

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

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    Process::assertNothingRan();
    expect($item->fresh()->prepared_path)->not->toBeNull()
        ->and($item->fresh()->loudness_lufs)->toBeNull();
});

test('it sends the stored credentials as basic auth when preparing an http source', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/protected.mp3', 'normalize' => false,
        'url_username' => 'radioring', 'url_password' => 'geheim123',
    ]);
    $item = externalItem($this->station, $source, '10:01:00');

    Http::fake(['example.com/*' => Http::response('AUDIO', 200)]);
    Process::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('radioring:geheim123')));
    expect($item->fresh()->prepared_path)->not->toBeNull();
});

test('it records a readable error for an address the fetcher cannot handle', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url', 'url' => 'sftp://files.example.com/show.mp3',
    ]);
    $item = externalItem($this->station, $source, '10:01:00');

    Http::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    Http::assertNothingSent();
    expect($item->fresh()->prepared_path)->toBeNull()
        ->and($source->fresh()->last_error)->toContain('sftp');
});

/**
 * Puts the pull cursor of the station on a rundown, the way a running container leaves it.
 */
function placeCursor(Station $station, GeneratedPlaylist $rundown, int $position = 0): void
{
    LiquidsoapState::updateOrCreate(
        ['station_id' => $station->id],
        ['current_rundown_id' => $rundown->id, 'current_item_position' => $position],
    );
}

test('prepares an item the cursor is about to reach, long before its lead opens', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/show.mp3', 'prefetch_lead_seconds' => 1800, 'normalize' => false,
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(), 'broadcast_hour' => 10, 'status' => 'ready',
    ]);

    // A show in half hour parts: the third part airs in 90 minutes, far outside the 30
    // minute lead, but Liquidsoap pulls it once the first part is handed out.
    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'external_source_id' => $source->id,
        'position' => 2,
        'source_type' => 'external',
        'title' => 'Show part 3',
        'duration_seconds' => 1800,
        'absolute_broadcast_at' => now()->addMinutes(90),
    ]);

    placeCursor($this->station, $rundown);

    Http::fake(['example.com/*' => Http::response('AUDIO', 200)]);
    Process::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    expect($item->fresh()->prepared_path)->not->toBeNull();
});

test('leaves an item beyond the pull horizon alone', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/show.mp3', 'prefetch_lead_seconds' => 1800,
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(), 'broadcast_hour' => 10, 'status' => 'ready',
    ]);

    // The horizon counts elements, so the hour has to be filled up to push the external
    // one out of reach.
    foreach (range(0, 39) as $position) {
        GeneratedPlaylistItem::factory()->create([
            'generated_playlist_id' => $rundown->id,
            'position' => $position,
            'source_type' => 'media',
            'title' => 'Track '.$position,
        ]);
    }

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'external_source_id' => $source->id,
        'position' => 40,
        'source_type' => 'external',
        'title' => 'Far away',
        'absolute_broadcast_at' => now()->addHours(4),
    ]);

    placeCursor($this->station, $rundown);

    Http::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    expect($item->fresh()->prepared_path)->toBeNull();
    Http::assertNothingSent();
});

test('does not refresh a prepared copy that is still far from its airtime', function () {
    $source = ExternalSource::factory()->create([
        'station_id' => $this->station->id, 'kind' => 'url',
        'url' => 'https://example.com/news.mp3', 'prefetch_lead_seconds' => 300,
        'freshness_seconds' => 600,
    ]);

    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(), 'broadcast_hour' => 10, 'status' => 'ready',
    ]);

    $path = "stations/{$this->station->slug}/prepared/kept.mp3";
    Storage::disk('local')->put($path, 'AUDIO');

    // Inside the pull horizon, prepared well over the freshness window ago, but its airtime
    // is an hour out: refetching now would only throw away the copy Liquidsoap holds.
    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'external_source_id' => $source->id,
        'position' => 1,
        'source_type' => 'external',
        'title' => 'News',
        'absolute_broadcast_at' => now()->addHour(),
        'prepared_path' => $path,
        'prepared_at' => now()->subMinutes(30),
    ]);

    placeCursor($this->station, $rundown);

    Http::fake();

    (new PrepareUpcomingHttpItemsJob)->handle(app(ExternalItemPreparer::class), app(LiquidsoapStateService::class));

    Http::assertNothingSent();
    expect($item->fresh()->prepared_at->toDateTimeString())->toBe(now()->subMinutes(30)->toDateTimeString());
});
