<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class LoudnessAnalyzerService
{
    /**
     * Misst die EBU-R128-Lautheit einer Audiodatei offline per ffmpeg.
     *
     * Nutzt den loudnorm-Filter im Mess-Modus (print_format=json). Der Zielwert (I=)
     * beeinflusst die ausgegebenen Eingangsmesswerte input_i/input_tp NICHT – die
     * werden aus dem Quellmaterial gemessen –, daher ein fester Platzhalter.
     *
     * @return array{lufs: float, true_peak: float}|null Null, wenn ffmpeg fehlt,
     *                                                   die Datei nicht dekodierbar
     *                                                   ist oder kein JSON liefert.
     */
    public function analyze(string $absolutePath): ?array
    {
        $binary = config('radioring.loudness.ffmpeg_path', 'ffmpeg');

        $result = Process::timeout(300)->run([
            $binary,
            '-hide_banner',
            '-nostats',
            '-i', $absolutePath,
            '-af', 'loudnorm=I=-16:print_format=json',
            '-f', 'null',
            '-',
        ]);

        // loudnorm schreibt den JSON-Block nach stderr.
        return $this->parse($result->errorOutput().$result->output());
    }

    /**
     * Extrahiert den letzten JSON-Block aus der ffmpeg-Ausgabe und liest die
     * gemessenen Werte aus.
     *
     * @return array{lufs: float, true_peak: float}|null
     */
    private function parse(string $output): ?array
    {
        if (! preg_match('/\{[^{}]*"input_i"[^{}]*\}/s', $output, $matches)) {
            return null;
        }

        $data = json_decode($matches[0], true);

        if (! is_array($data) || ! isset($data['input_i'], $data['input_tp'])) {
            return null;
        }

        $lufs = (float) $data['input_i'];
        $truePeak = (float) $data['input_tp'];

        // ffmpeg liefert bei Stille -inf o.ä. als String – unbrauchbare Messung verwerfen.
        if (! is_finite($lufs) || ! is_finite($truePeak) || $lufs <= -70.0) {
            return null;
        }

        return ['lufs' => $lufs, 'true_peak' => $truePeak];
    }
}
