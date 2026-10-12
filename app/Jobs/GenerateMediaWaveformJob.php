<?php

namespace App\Jobs;

use App\Models\MediaFile;
use App\Services\WaveformGeneratorService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Precomputes the waveform peaks of a media file for the editor in the FileModal, and
 * stores the exact file length found on the way.
 *
 * Unique per file: opening the dialog of a file without waveform queues it as well, and
 * reopening the dialog must not pile up copies while the first one waits.
 */
class GenerateMediaWaveformJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Decoding a long file takes a while, so it runs on the long running queue next to the
     * loudness analysis.
     */
    public function __construct(public readonly int $mediaFileId)
    {
        $this->onConnection('media');
    }

    public function uniqueId(): string
    {
        return (string) $this->mediaFileId;
    }

    public function handle(WaveformGeneratorService $generator): void
    {
        $file = MediaFile::find($this->mediaFileId);

        if (! $file) {
            return;
        }

        $disk = Storage::disk('local');
        $analysedPath = $file->file_path;

        if (! $disk->exists($analysedPath)) {
            Log::warning("Waveform skipped, file missing: {$analysedPath}");

            return;
        }

        $waveform = $generator->generate($disk->path($analysedPath));

        if ($waveform === null) {
            // Undecodable file: no retry, the dialog simply shows no waveform.
            Log::warning("Waveform could not be generated for media file #{$file->id} ({$analysedPath}).");

            return;
        }

        // The file may have been replaced while ffmpeg was busy. Its own job follows.
        if (MediaFile::whereKey($file->id)->value('file_path') !== $analysedPath) {
            return;
        }

        $waveformPath = $file->waveformStoragePath();
        $disk->put($waveformPath, json_encode($waveform, JSON_THROW_ON_ERROR));

        // Decoding the whole file gives the exact length, unlike getID3's whole seconds or a
        // header estimate. Segues need it as cue out.
        $file->update([
            'waveform_path' => $waveformPath,
            'duration_seconds' => $waveform['duration'],
        ]);
    }
}
