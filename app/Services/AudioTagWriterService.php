<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Writes title, artist and album into an audio file.
 *
 * ffmpeg remuxes the file with stream copy: the audio stays bit-identical, so the measured
 * loudness keeps its value, and every other tag (cover art, track number, ISRC) is carried
 * over. getID3's writer cannot merge with existing tags and would drop them.
 *
 * The result goes to a temporary file next to the original and replaces it with one
 * rename. Liquidsoap or a preview may be reading the file at that moment; they keep the
 * old copy instead of seeing a half-written one.
 */
class AudioTagWriterService
{
    /** @var list<string> */
    public const SUPPORTED_EXTENSIONS = ['mp3', 'flac', 'ogg', 'm4a'];

    /** Largest length difference (seconds) tolerated between the original and the rewritten file. */
    private const DURATION_TOLERANCE_SECONDS = 1;

    public function __construct(private readonly AudioMetadataService $metadata) {}

    public function supports(string $absolutePath): bool
    {
        return in_array(strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)), self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * An empty or null value removes that tag from the file.
     *
     * @param  array{title: ?string, artist: ?string, album: ?string}  $tags
     * @return bool true when the file was rewritten
     */
    public function write(string $absolutePath, array $tags): bool
    {
        if (! $this->supports($absolutePath) || ! is_file($absolutePath)) {
            return false;
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $temporaryPath = dirname($absolutePath).'/.'.pathinfo($absolutePath, PATHINFO_FILENAME).'.retag.'.$extension;

        try {
            $result = Process::timeout(300)->run($this->command($absolutePath, $temporaryPath, $extension, $tags));

            if (! $result->successful() || ! is_file($temporaryPath) || filesize($temporaryPath) === 0) {
                Log::warning("Tags could not be written to {$absolutePath}: ".trim($result->errorOutput()));

                return false;
            }

            if (! $this->keepsLength($absolutePath, $temporaryPath)) {
                Log::warning("Tags not written to {$absolutePath}: the rewritten file has a different length.");

                return false;
            }

            return rename($temporaryPath, $absolutePath);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param  array{title: ?string, artist: ?string, album: ?string}  $tags
     * @return list<string>
     */
    private function command(string $source, string $target, string $extension, array $tags): array
    {
        $command = [
            config('radioring.loudness.ffmpeg_path', 'ffmpeg'),
            '-hide_banner',
            '-nostats',
            '-loglevel', 'error',
            '-y',
            '-i', $source,
            // All streams, cover art included, copied as they are.
            '-map', '0',
            '-c', 'copy',
            '-map_metadata', '0',
        ];

        foreach (['title', 'artist', 'album'] as $key) {
            $command[] = '-metadata';
            $command[] = $key.'='.trim((string) ($tags[$key] ?? ''));
        }

        if ($extension === 'mp3') {
            // ID3v2.3 is what older players and Windows Explorer read reliably.
            $command[] = '-id3v2_version';
            $command[] = '3';
        }

        $command[] = $target;

        return $command;
    }

    /** Guards against a remux that lost or truncated audio. */
    private function keepsLength(string $originalPath, string $rewrittenPath): bool
    {
        $original = $this->metadata->read($originalPath)['duration'];
        $rewritten = $this->metadata->read($rewrittenPath)['duration'];

        if ($original === null || $rewritten === null) {
            return false;
        }

        return abs($original - $rewritten) <= self::DURATION_TOLERANCE_SECONDS;
    }
}
