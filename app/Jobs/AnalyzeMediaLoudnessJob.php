<?php

namespace App\Jobs;

use App\Models\MediaFile;
use App\Services\LoudnessAnalyzerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnalyzeMediaLoudnessJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $mediaFileId) {}

    public function handle(LoudnessAnalyzerService $analyzer): void
    {
        $file = MediaFile::find($this->mediaFileId);

        if (! $file) {
            return;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($file->file_path)) {
            Log::warning("Lautheitsmessung übersprungen – Datei fehlt: {$file->file_path}");

            return;
        }

        $measurement = $analyzer->analyze($disk->path($file->file_path));

        if ($measurement === null) {
            // Nicht messbar (z. B. defekte Frames): kein Wert → Track spielt ohne
            // Gain-Korrektur (0 dB). Kein erneuter Versuch, sonst Endlos-Retry.
            Log::warning("Lautheit nicht messbar für Medien-Datei #{$file->id} ({$file->file_path}).");

            return;
        }

        $file->update([
            'loudness_lufs' => $measurement['lufs'],
            'loudness_true_peak' => $measurement['true_peak'],
            'loudness_measured_at' => now(),
        ]);
    }
}
