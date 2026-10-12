<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class WaveformGeneratorService
{
    /** Resolution of the waveform: 50 peaks per second, one every 20 ms. */
    public const PEAKS_PER_SECOND = 50;

    /** Sample rate ffmpeg decodes to. Plenty for an amplitude envelope. */
    public const SAMPLE_RATE = 8000;

    /** Buckets read from the PCM file per chunk; keeps memory flat on hour long files. */
    private const BUCKETS_PER_CHUNK = 2000;

    /**
     * Computes the waveform of an audio file offline with ffmpeg.
     *
     * ffmpeg decodes to mono 16 bit PCM in a temp file, which is then read in chunks:
     * one hour at 8 kHz is about 58 MB of PCM, far too much to keep in memory at once.
     * Each bucket stores the absolute peak, scaled to 0..255. Returns null if ffmpeg
     * fails or the file holds no audio.
     *
     * @return array{version: int, peaks_per_second: int, duration: float, peaks: list<int>}|null
     */
    public function generate(string $absolutePath): ?array
    {
        $binary = config('radioring.loudness.ffmpeg_path', 'ffmpeg');
        $pcmPath = tempnam(sys_get_temp_dir(), 'waveform_').'.pcm';

        try {
            $result = Process::timeout(600)->run([
                $binary,
                '-hide_banner',
                '-nostats',
                '-y',
                '-i', $absolutePath,
                '-vn',
                '-ac', '1',
                '-ar', (string) self::SAMPLE_RATE,
                '-f', 's16le',
                $pcmPath,
            ]);

            if (! $result->successful() || ! is_file($pcmPath) || filesize($pcmPath) === 0) {
                return null;
            }

            return $this->peaksFromPcm($pcmPath);
        } finally {
            @unlink($pcmPath);
            @unlink(substr($pcmPath, 0, -4));
        }
    }

    /**
     * Reads mono s16le PCM and reduces it to one peak per bucket.
     *
     * @return array{version: int, peaks_per_second: int, duration: float, peaks: list<int>}|null
     */
    private function peaksFromPcm(string $pcmPath): ?array
    {
        $samplesPerBucket = intdiv(self::SAMPLE_RATE, self::PEAKS_PER_SECOND);
        $chunkBytes = $samplesPerBucket * 2 * self::BUCKETS_PER_CHUNK;

        $handle = fopen($pcmPath, 'rb');

        if ($handle === false) {
            return null;
        }

        $peaks = [];
        $sampleCount = 0;

        try {
            while (! feof($handle)) {
                $bytes = fread($handle, $chunkBytes);

                if ($bytes === false || strlen($bytes) < 2) {
                    break;
                }

                // An odd trailing byte cannot form a sample.
                if (strlen($bytes) % 2 === 1) {
                    $bytes = substr($bytes, 0, -1);
                }

                // "s" is machine byte order; every supported host is little endian like s16le.
                $samples = array_values(unpack('s*', $bytes));
                $sampleCount += count($samples);

                foreach (array_chunk($samples, $samplesPerBucket) as $bucket) {
                    $peak = max(max($bucket), -min($bucket));
                    $peaks[] = min(255, (int) round($peak / 32768 * 255));
                }
            }
        } finally {
            fclose($handle);
        }

        if ($peaks === []) {
            return null;
        }

        return [
            'version' => 1,
            'peaks_per_second' => self::PEAKS_PER_SECOND,
            'duration' => round($sampleCount / self::SAMPLE_RATE, 3),
            'peaks' => $peaks,
        ];
    }
}
