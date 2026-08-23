<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class SilenceTrimmerService
{
    /**
     * Schneidet führende Stille einer Audiodatei offline per ffmpeg weg und schreibt
     * das Ergebnis zurück in dieselbe Datei (re-encode als MP3).
     *
     * Nutzt den silenceremove-Filter (start_periods=1): alles vom Dateianfang bis zum
     * ersten Ton oberhalb der Schwelle wird entfernt. Stille MITTEN im Material bleibt
     * unberührt. Schlägt ffmpeg fehl, bleibt die Originaldatei erhalten (return false).
     *
     * @param  float  $thresholdDb  Pegel (dB), unter dem Audio als Stille gilt (z.B. -45).
     */
    public function trimLeadingSilence(string $absolutePath, float $thresholdDb): bool
    {
        $binary = config('radioring.loudness.ffmpeg_path', 'ffmpeg');
        $tmpPath = $absolutePath.'.trimmed.mp3';

        $result = Process::timeout(300)->run([
            $binary,
            '-hide_banner',
            '-nostats',
            '-y',
            '-i', $absolutePath,
            '-af', "silenceremove=start_periods=1:start_threshold={$thresholdDb}dB",
            $tmpPath,
        ]);

        if (! $result->successful() || ! is_file($tmpPath) || filesize($tmpPath) === 0) {
            @unlink($tmpPath);

            return false;
        }

        return @rename($tmpPath, $absolutePath);
    }
}
