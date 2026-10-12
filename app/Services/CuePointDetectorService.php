<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Suggests cue points from silence: cue in where the leading silence ends, cue out where
 * the trailing silence starts. Uses ffmpeg's silencedetect, which passes the audio through
 * unchanged and can therefore share a decoding pass with the loudness measurement.
 */
class CuePointDetectorService
{
    /** Shorter offsets are not worth a cue point (encoder padding, a click of silence). */
    private const MIN_OFFSET_SECONDS = 0.05;

    /**
     * How close a silence must end to the decoded length to count as running into the end.
     * ffmpeg's progress time runs up to a frame (and loudnorm's buffering) past the last
     * silence_end it prints at the end of the stream: 190.10 s against 190.04 s in practice.
     */
    private const END_TOLERANCE_SECONDS = 0.5;

    /**
     * Runs a silence detection pass of its own, for the analysis button in the file dialog.
     *
     * @return array{cue_in: ?float, cue_out: ?float}|null Null when ffmpeg fails.
     */
    public function detect(string $absolutePath): ?array
    {
        $result = Process::timeout(300)->run([
            config('radioring.loudness.ffmpeg_path', 'ffmpeg'),
            '-hide_banner',
            '-nostats',
            '-progress', 'pipe:1',
            '-i', $absolutePath,
            '-af', $this->filter(),
            '-f', 'null',
            '-',
        ]);

        if (! $result->successful()) {
            return null;
        }

        return $this->parse($result->errorOutput().$result->output());
    }

    /** The silencedetect filter, to chain in front of another analysis filter. */
    public function filter(): string
    {
        $threshold = (float) config('radioring.cue_detection.threshold_db', -50.0);
        $minimum = (float) config('radioring.cue_detection.min_silence_seconds', 0.1);

        return "silencedetect=noise={$threshold}dB:d={$minimum}";
    }

    /**
     * Reads the silences from ffmpeg's output. The decoded length comes from
     * `-progress pipe:1` (out_time_us), not from the container header, which is wrong for
     * VBR files without a Xing header.
     *
     * @return array{cue_in: ?float, cue_out: ?float}
     */
    public function parse(string $output): array
    {
        preg_match_all('/silence_(start|end): (-?[0-9.]+)/', $output, $matches, PREG_SET_ORDER);

        $silences = [];

        foreach ($matches as [, $kind, $value]) {
            if ($kind === 'start') {
                $silences[] = ['start' => max(0.0, (float) $value), 'end' => null];
            } elseif ($silences !== []) {
                $silences[array_key_last($silences)]['end'] = (float) $value;
            }
        }

        $length = preg_match_all('/out_time_us=(\d+)/', $output, $times) ? end($times[1]) / 1_000_000 : null;

        $none = ['cue_in' => null, 'cue_out' => null];

        if ($silences === []) {
            return $none;
        }

        $first = $silences[0];
        $last = $silences[array_key_last($silences)];

        // Silence from start to end: nothing to suggest.
        if ($first['start'] < self::MIN_OFFSET_SECONDS && ($first['end'] === null || ($length !== null && $first['end'] >= $length - self::END_TOLERANCE_SECONDS))) {
            return $none;
        }

        $leading = $first['start'] < self::MIN_OFFSET_SECONDS;
        $cueIn = $leading ? $first['end'] : null;

        // Older ffmpeg leaves a silence running into the end open, newer ones close it at the end.
        $reachesEnd = $last['end'] === null || ($length !== null && $last['end'] >= $length - self::END_TOLERANCE_SECONDS);
        // A single silence is either the leading or the trailing one, never both.
        $trailing = $reachesEnd && (count($silences) > 1 || ! $leading);
        $cueOut = $trailing ? $last['start'] : null;

        return [
            'cue_in' => $cueIn !== null && $cueIn >= self::MIN_OFFSET_SECONDS ? round($cueIn, 2) : null,
            'cue_out' => $cueOut !== null ? round($cueOut, 2) : null,
        ];
    }
}
