<?php

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Livewire\MediaLibrary\FileModal;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use App\Services\CuePointDetectorService;
use App\Services\LoudnessAnalyzerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
});

/**
 * What ffmpeg prints for silencedetect (stderr) and -progress pipe:1 (stdout), as one string.
 *
 * @param  list<array{0: float, 1: ?float}>  $silences  start and end, end null = left open
 */
function silencedetectOutput(array $silences, ?float $length): string
{
    $lines = [];

    foreach ($silences as [$start, $end]) {
        $lines[] = "[silencedetect @ 0x7f2b] silence_start: {$start}";

        if ($end !== null) {
            $lines[] = "[silencedetect @ 0x7f2b] silence_end: {$end} | silence_duration: ".($end - $start);
        }
    }

    if ($length !== null) {
        $lines[] = 'out_time_us='.(int) round($length * 1_000_000);
        $lines[] = 'progress=end';
    }

    return implode("\n", $lines);
}

/** Fakes the combined ffmpeg pass of the loudness job: loudnorm JSON plus silences. */
function fakeAnalysis(array $silences, float $length, ?Closure $whileRunning = null): void
{
    $loudnorm = '{"input_i" : "-20.00", "input_tp" : "-3.00", "input_lra" : "5.00"}';

    Process::fake(function (PendingProcess $process) use ($silences, $length, $loudnorm, $whileRunning) {
        if ($whileRunning) {
            $whileRunning();
        }

        return Process::result(output: 'out_time_us='.(int) ($length * 1_000_000), errorOutput: silencedetectOutput($silences, null)."\n".$loudnorm);
    });
}

function analysedMediaFile(Station $station, array $attributes = []): MediaFile
{
    $path = "tenants/{$station->tenant_id}/media/song.mp3";
    Storage::disk('local')->put($path, 'audio-bytes');

    return MediaFile::factory()->create([
        'tenant_id' => $station->tenant_id,
        'file_path' => $path,
        'duration_seconds' => 9.5,
        // Opening the dialog would otherwise queue the waveform job, which runs ffmpeg too.
        'waveform_path' => "tenants/{$station->tenant_id}/waveforms/song.json",
        ...$attributes,
    ]);
}

test('silences at the start and end become cue points', function (array $silences, ?float $length, ?float $cueIn, ?float $cueOut) {
    expect(app(CuePointDetectorService::class)->parse(silencedetectOutput($silences, $length)))
        ->toBe(['cue_in' => $cueIn, 'cue_out' => $cueOut]);
})->with([
    'leading and trailing, closed at the end (ffmpeg 7)' => [[[0, 1.5], [6.5, 9.5]], 9.5, 1.5, 6.5],
    'trailing left open (older ffmpeg)' => [[[0, 1.5], [6.5, null]], 9.5, 1.5, 6.5],
    'progress time a little past the last silence_end (real ffmpeg 7 output)' => [[[0, 1.525079], [186.525057, 190.040816]], 190.1, 1.53, 186.53],
    'audio after the last silence is not a trailing silence' => [[[0, 1.0], [8.0, 9.0]], 10.0, 1.0, null],
    'only leading' => [[[0, 2.256]], 10.0, 2.26, null],
    'only trailing' => [[[8.0, 10.0]], 10.0, null, 8.0],
    'silence in the middle is ignored' => [[[3.0, 4.0]], 10.0, null, null],
    'middle silence between leading and trailing' => [[[0, 1.0], [4.0, 5.0], [9.0, 10.0]], 10.0, 1.0, 9.0],
    'encoder padding at the start is too short' => [[[0, 0.02], [9.0, 10.0]], 10.0, null, 9.0],
    'whole file silent' => [[[0, 10.0]], 10.0, null, null],
    'whole file silent, left open' => [[[0, null]], 10.0, null, null],
    'no silence at all' => [[], 10.0, null, null],
    'unknown length: a closed silence could be followed by audio' => [[[0, 1.0], [9.0, 10.0]], null, 1.0, null],
]);

test('the detector runs ffmpeg with silencedetect and the configured threshold', function () {
    config(['radioring.cue_detection.threshold_db' => -42.0]);
    Process::fake(['*' => Process::result(output: 'out_time_us=9500000', errorOutput: silencedetectOutput([[0, 1.5]], null))]);

    expect(app(CuePointDetectorService::class)->detect('/audio/song.mp3'))->toBe(['cue_in' => 1.5, 'cue_out' => null]);

    Process::assertRan(fn (PendingProcess $process) => in_array('silencedetect=noise=-42dB:d=0.1', $process->command, true));
});

test('the detector returns null when ffmpeg fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Invalid data found', exitCode: 1)]);

    expect(app(CuePointDetectorService::class)->detect('/audio/broken.mp3'))->toBeNull();
});

test('the analysis of a new file sets loudness and the suggested cue points in one pass', function () {
    $file = analysedMediaFile($this->station);
    fakeAnalysis([[0, 1.5], [6.5, 9.5]], 9.5);

    (new AnalyzeMediaLoudnessJob($file->id, applyCuePoints: true))->handle(app(LoudnessAnalyzerService::class));

    expect($file->refresh())
        ->loudness_lufs->toBe(-20.0)
        ->cue_in_seconds->toBe(1.5)
        ->cue_out_seconds->toBe(6.5);

    Process::assertRanTimes(fn () => true, 1);
});

test('the analysis keeps cue points somebody set', function () {
    $file = analysedMediaFile($this->station, ['cue_in_seconds' => 3.0]);
    fakeAnalysis([[0, 1.5], [6.5, 9.5]], 9.5);

    (new AnalyzeMediaLoudnessJob($file->id, applyCuePoints: true))->handle(app(LoudnessAnalyzerService::class));

    expect($file->refresh())
        ->cue_in_seconds->toBe(3.0)
        ->cue_out_seconds->toBe(6.5);
});

test('without the flag the analysis only measures loudness', function () {
    $file = analysedMediaFile($this->station);
    fakeAnalysis([[0, 1.5], [6.5, 9.5]], 9.5);

    (new AnalyzeMediaLoudnessJob($file->id))->handle(app(LoudnessAnalyzerService::class));

    expect($file->refresh())
        ->loudness_lufs->toBe(-20.0)
        ->cue_in_seconds->toBeNull()
        ->cue_out_seconds->toBeNull();
});

test('suggested cue points are dropped when the fades of the file no longer fit', function () {
    $file = analysedMediaFile($this->station, ['fade_in_seconds' => 3.0, 'fade_out_seconds' => 3.0]);
    fakeAnalysis([[0, 1.5], [6.5, 9.5]], 9.5);

    (new AnalyzeMediaLoudnessJob($file->id, applyCuePoints: true))->handle(app(LoudnessAnalyzerService::class));

    expect($file->refresh())
        ->loudness_lufs->toBe(-20.0)
        ->cue_in_seconds->toBeNull()
        ->cue_out_seconds->toBeNull();
});

test('the analysis discards its result when the file was replaced meanwhile', function () {
    $file = analysedMediaFile($this->station);
    $newPath = "tenants/{$this->station->tenant_id}/media/new.mp3";
    fakeAnalysis([[0, 1.5], [6.5, 9.5]], 9.5, fn () => MediaFile::whereKey($file->id)->update(['file_path' => $newPath]));

    (new AnalyzeMediaLoudnessJob($file->id, applyCuePoints: true))->handle(app(LoudnessAnalyzerService::class));

    expect($file->refresh())
        ->loudness_lufs->toBeNull()
        ->cue_in_seconds->toBeNull();
});

test('the analysis button fills the cue fields without saving them', function () {
    $file = analysedMediaFile($this->station);
    Process::fake(['*' => Process::result(output: 'out_time_us=9500000', errorOutput: silencedetectOutput([[0, 1.5], [6.5, 9.5]], null))]);

    $this->actingAs($this->user);
    session(['current_station_id' => $this->station->id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->set('cueIn', '4')
        ->call('analyzeCuePoints')
        ->assertSet('cueIn', '1.5')
        ->assertSet('cueOut', '6.5')
        ->assertDispatched('notify', type: 'info');

    expect($file->refresh()->cue_in_seconds)->toBeNull();
});

test('the analysis button clears the fields when there is no silence', function () {
    $file = analysedMediaFile($this->station, ['cue_in_seconds' => 2.0]);
    Process::fake(['*' => Process::result(output: 'out_time_us=9500000', errorOutput: '')]);

    $this->actingAs($this->user);
    session(['current_station_id' => $this->station->id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->assertSet('cueIn', '2')
        ->call('analyzeCuePoints')
        ->assertSet('cueIn', '')
        ->assertSet('cueOut', '')
        ->assertDispatched('notify', message: __('No silence found at the start or end.'));
});

test('the analysis button reports a file it cannot analyse', function () {
    $file = analysedMediaFile($this->station);
    Storage::disk('local')->delete($file->file_path);
    Process::fake();

    $this->actingAs($this->user);
    session(['current_station_id' => $this->station->id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->call('analyzeCuePoints')
        ->assertDispatched('notify', type: 'error');

    Process::assertNothingRan();
});

test('a presenter cannot run the analysis on a file they did not upload', function () {
    $presenter = User::factory()->create();
    $this->station->members()->attach($presenter->id, ['role' => 'presenter']);
    $file = analysedMediaFile($this->station);
    Process::fake();

    $this->actingAs($presenter);
    session(['current_station_id' => $this->station->id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->call('analyzeCuePoints')
        ->assertForbidden();

    Process::assertNothingRan();
});

test('the command limits to the station library and catches up on cue points', function () {
    Queue::fake();
    $other = Station::factory()->create();

    $unmeasured = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
    $measuredWithoutCues = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'loudness_measured_at' => now()]);
    $measuredWithCues = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'loudness_measured_at' => now(), 'cue_in_seconds' => 1.0]);
    $foreign = MediaFile::factory()->create(['tenant_id' => $other->tenant_id]);

    $this->artisan('media:measure-loudness', ['--station' => $this->station->slug, '--cue-points' => true])->assertSuccessful();

    $queued = Queue::pushed(AnalyzeMediaLoudnessJob::class);
    expect($queued->map->mediaFileId->sort()->values()->all())->toBe([$unmeasured->id, $measuredWithoutCues->id])
        ->and($queued->every->applyCuePoints)->toBeTrue();
});

test('the command without cue points only measures what has no loudness yet', function () {
    Queue::fake();

    $unmeasured = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'loudness_measured_at' => now()]);

    $this->artisan('media:measure-loudness', ['--station' => (string) $this->station->id])->assertSuccessful();

    $queued = Queue::pushed(AnalyzeMediaLoudnessJob::class);
    expect($queued->map->mediaFileId->all())->toBe([$unmeasured->id])
        ->and($queued->first()->applyCuePoints)->toBeFalse();
});
