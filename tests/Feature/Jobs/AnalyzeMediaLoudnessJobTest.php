<?php

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Models\MediaFile;
use App\Models\Station;
use App\Services\LoudnessAnalyzerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create();
});

function fakeLoudnorm(string $inputI, string $inputTp): void
{
    $json = <<<JSON
[Parsed_loudnorm_0 @ 0x000]
{
	"input_i" : "{$inputI}",
	"input_tp" : "{$inputTp}",
	"input_lra" : "7.00",
	"input_thresh" : "-30.80",
	"output_i" : "-16.00",
	"normalization_type" : "dynamic"
}
JSON;

    // loudnorm schreibt den JSON-Block nach stderr.
    Process::fake(['*' => Process::result(output: '', errorOutput: $json)]);
}

test('job stores the measured loudness and true peak', function () {
    Storage::fake('local');
    $path = "tenants/{$this->station->tenant_id}/media/song.mp3";
    Storage::disk('local')->put($path, 'audio-bytes');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => $path,
        'loudness_lufs' => null,
    ]);

    fakeLoudnorm('-20.50', '-3.20');

    (new AnalyzeMediaLoudnessJob($file->id))->handle(app(LoudnessAnalyzerService::class));

    $file->refresh();
    expect($file->loudness_lufs)->toBe(-20.5)
        ->and($file->loudness_true_peak)->toBe(-3.2)
        ->and($file->loudness_measured_at)->not->toBeNull();
});

test('job leaves loudness null when ffmpeg yields no parsable measurement', function () {
    Storage::fake('local');
    $path = "tenants/{$this->station->tenant_id}/media/broken.mp3";
    Storage::disk('local')->put($path, 'garbage');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => $path,
        'loudness_lufs' => null,
    ]);

    Process::fake(['*' => Process::result(output: '', errorOutput: 'Header missing\nInvalid data found')]);

    (new AnalyzeMediaLoudnessJob($file->id))->handle(app(LoudnessAnalyzerService::class));

    expect($file->refresh()->loudness_lufs)->toBeNull()
        ->and($file->loudness_measured_at)->toBeNull();
});

test('job skips silently when the file is missing on disk', function () {
    Storage::fake('local');

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'file_path' => 'stations/none/media/ghost.mp3',
        'loudness_lufs' => null,
    ]);

    Process::fake();

    (new AnalyzeMediaLoudnessJob($file->id))->handle(app(LoudnessAnalyzerService::class));

    Process::assertNothingRan();
    expect($file->refresh()->loudness_lufs)->toBeNull();
});
