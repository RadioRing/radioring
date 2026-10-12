<?php

use App\Jobs\GenerateMediaWaveformJob;
use App\Models\MediaFile;
use App\Models\Station;
use App\Services\WaveformGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->station = Station::factory()->create();
});

/**
 * Lets the faked ffmpeg write the given 16 bit samples into the PCM file it was asked for.
 *
 * @param  list<int>  $samples
 */
function fakeFfmpegPcm(array $samples, ?Closure $whileRunning = null): void
{
    Process::fake(function (PendingProcess $process) use ($samples, $whileRunning) {
        file_put_contents(end($process->command), pack('s*', ...$samples));

        if ($whileRunning) {
            $whileRunning();
        }

        return Process::result();
    });
}

function waveformMediaFile(Station $station, string $name = 'song.mp3'): MediaFile
{
    $path = "tenants/{$station->tenant_id}/media/{$name}";
    Storage::disk('local')->put($path, 'audio-bytes');

    return MediaFile::factory()->create(['tenant_id' => $station->tenant_id, 'file_path' => $path]);
}

test('the generator reduces PCM to one scaled peak per bucket', function () {
    $samplesPerBucket = intdiv(WaveformGeneratorService::SAMPLE_RATE, WaveformGeneratorService::PEAKS_PER_SECOND);

    // Bucket 1 silent, bucket 2 a negative full scale peak, bucket 3 half scale, then a short tail.
    $samples = [
        ...array_fill(0, $samplesPerBucket, 0),
        ...array_fill(0, $samplesPerBucket - 1, 100), -32768,
        ...array_fill(0, $samplesPerBucket, 16384),
        ...array_fill(0, 10, 8192),
    ];

    fakeFfmpegPcm($samples);

    $waveform = app(WaveformGeneratorService::class)->generate('/audio/song.mp3');

    expect($waveform)
        ->version->toBe(1)
        ->peaks_per_second->toBe(WaveformGeneratorService::PEAKS_PER_SECOND)
        ->peaks->toBe([0, 255, 128, 64])
        ->duration->toBe(round(count($samples) / WaveformGeneratorService::SAMPLE_RATE, 3));
});

test('the generator returns null when ffmpeg fails', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'Invalid data found', exitCode: 1)]);

    expect(app(WaveformGeneratorService::class)->generate('/audio/broken.mp3'))->toBeNull();
});

test('the job stores the waveform and remembers its path', function () {
    $file = waveformMediaFile($this->station);

    fakeFfmpegPcm(array_fill(0, 320, 16384));

    (new GenerateMediaWaveformJob($file->id))->handle(app(WaveformGeneratorService::class));

    $file->refresh();

    expect($file->waveform_path)->toBe("tenants/{$this->station->tenant_id}/waveforms/{$file->id}.json");

    $stored = json_decode(Storage::disk('local')->get($file->waveform_path), true);
    expect($stored['peaks'])->toBe([128, 128]);
});

test('the job replaces the rounded length with the exact decoded one', function () {
    $file = waveformMediaFile($this->station);
    $file->update(['duration_seconds' => 1]);

    // 10 000 samples at 8 kHz: 1.25 s, which getID3 would have stored as 1.
    fakeFfmpegPcm(array_fill(0, 10_000, 100));

    (new GenerateMediaWaveformJob($file->id))->handle(app(WaveformGeneratorService::class));

    expect($file->refresh()->duration_seconds)->toBe(1.25)
        ->and($file->durationFormatted())->toBe('0:01');
});

test('the job discards its result when the file was replaced meanwhile', function () {
    $file = waveformMediaFile($this->station);
    $newPath = "tenants/{$this->station->tenant_id}/media/new.mp3";

    fakeFfmpegPcm([1000, 2000], fn () => MediaFile::whereKey($file->id)->update(['file_path' => $newPath]));

    (new GenerateMediaWaveformJob($file->id))->handle(app(WaveformGeneratorService::class));

    expect($file->refresh()->waveform_path)->toBeNull()
        ->and($file->duration_seconds)->not->toBe(0.0);
    Storage::disk('local')->assertMissing($file->waveformStoragePath());
});

test('the job skips silently when the file is missing on disk', function () {
    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => 'tenants/none/media/ghost.mp3',
    ]);

    Process::fake();

    (new GenerateMediaWaveformJob($file->id))->handle(app(WaveformGeneratorService::class));

    Process::assertNothingRan();
    expect($file->refresh()->waveform_path)->toBeNull();
});

test('deleting a media file removes its waveform', function () {
    $file = waveformMediaFile($this->station);
    $file->update(['waveform_path' => $file->waveformStoragePath()]);
    Storage::disk('local')->put($file->waveform_path, '{}');

    $file->delete();

    Storage::disk('local')->assertMissing("tenants/{$this->station->tenant_id}/waveforms/{$file->id}.json");
});
