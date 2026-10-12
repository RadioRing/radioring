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

    /**
     * ffmpeg needs minutes on a long file. That belongs on the long running queue, away
     * from the jobs the programme depends on.
     *
     * @param  bool  $applyCuePoints  Also set the cue points suggested by the silence
     *                                detection, where the file has none yet (new uploads,
     *                                a replaced file, `media:measure-loudness --cue-points`).
     */
    public function __construct(public readonly int $mediaFileId, public readonly bool $applyCuePoints = false)
    {
        $this->onConnection('media');
    }

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

        $analysedPath = $file->file_path;
        $measurement = $analyzer->measure($disk->path($analysedPath));

        // The file may have been replaced while ffmpeg was busy. Its own job follows.
        if (MediaFile::whereKey($file->id)->value('file_path') !== $analysedPath) {
            return;
        }

        $attributes = [];

        if ($measurement['loudness'] !== null) {
            $attributes = [
                'loudness_lufs' => $measurement['loudness']['lufs'],
                'loudness_true_peak' => $measurement['loudness']['true_peak'],
                'loudness_measured_at' => now(),
            ];
        } else {
            // Nicht messbar (z. B. defekte Frames): kein Wert → Track spielt ohne
            // Gain-Korrektur (0 dB). Kein erneuter Versuch, sonst Endlos-Retry.
            Log::warning("Lautheit nicht messbar für Medien-Datei #{$file->id} ({$file->file_path}).");
        }

        if ($this->applyCuePoints && $measurement['cue_points'] !== null) {
            $attributes += $this->suggestedCuePoints($file->refresh(), $measurement['cue_points']);
        }

        if ($attributes !== []) {
            $file->update($attributes);
        }
    }

    /**
     * The suggested cue points that may be stored: never over a cue point someone set, and
     * only when fades set on the file still fit into what remains.
     *
     * @param  array{cue_in: ?float, cue_out: ?float}  $suggestion
     * @return array{cue_in_seconds?: float, cue_out_seconds?: float}
     */
    private function suggestedCuePoints(MediaFile $file, array $suggestion): array
    {
        $cuePoints = array_filter([
            'cue_in_seconds' => $file->cue_in_seconds === null ? $suggestion['cue_in'] : null,
            'cue_out_seconds' => $file->cue_out_seconds === null ? $suggestion['cue_out'] : null,
        ], fn (?float $value): bool => $value !== null);

        $start = $cuePoints['cue_in_seconds'] ?? $file->cue_in_seconds ?? 0.0;
        $end = $cuePoints['cue_out_seconds'] ?? $file->cue_out_seconds ?? $file->duration_seconds;
        $fades = ($file->fade_in_seconds ?? 0.0) + ($file->fade_out_seconds ?? 0.0);

        if ($end !== null && $end - $start <= $fades) {
            return [];
        }

        return $cuePoints;
    }
}
