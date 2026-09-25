<?php

namespace App\Support;

use App\Models\MediaFile;
use App\Models\Station;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The files a station falls back to while the programme branch is unavailable.
 *
 * A name carries the media id and the file's updated_at, which is what lets the container
 * sync by filename alone: replacing a file changes updated_at and therefore the name.
 */
class EmergencyLoop
{
    /** @return Collection<int, MediaFile> */
    public static function files(Station $station): Collection
    {
        return $station->emergencyItems()->get();
    }

    public static function maxFiles(): int
    {
        return max(1, (int) config('radioring.emergency.max_files', 10));
    }

    public static function maxBytes(): int
    {
        return (int) config('radioring.emergency.max_bytes', 0);
    }

    public static function fileName(MediaFile $file): string
    {
        $extension = pathinfo((string) $file->file_path, PATHINFO_EXTENSION) ?: 'mp3';

        return $file->id.'-'.($file->updated_at?->timestamp ?? 0).'.'.$extension;
    }

    /** The media id a synced file name was built from, or null if it was not one of ours. */
    public static function mediaIdFromName(string $name): ?int
    {
        return preg_match('/^(\d+)-\d+\./', basename($name), $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /** Total size of the selected files on disk, for the cap shown in the panel. */
    public static function totalBytes(Station $station): int
    {
        return self::files($station)->sum(
            fn (MediaFile $file): int => Storage::disk('local')->exists($file->file_path)
                ? (int) Storage::disk('local')->size($file->file_path)
                : 0
        );
    }

    /**
     * What the container downloads. Files missing on disk are left out rather than
     * handed over as a dead link.
     *
     * @return list<array{name: string, bytes: int, amplify: ?string, url: string}>
     */
    public static function manifest(Station $station): array
    {
        $maxBytes = self::maxBytes();
        $loudness = (bool) config('radioring.loudness.enabled', true);

        $entries = [];
        $bytes = 0;

        foreach (self::files($station) as $file) {
            if (count($entries) >= self::maxFiles()) {
                break;
            }

            if (! Storage::disk('local')->exists($file->file_path)) {
                continue;
            }

            $size = (int) Storage::disk('local')->size($file->file_path);

            if ($maxBytes > 0 && $bytes + $size > $maxBytes) {
                continue;
            }

            $bytes += $size;
            $gainDb = $loudness ? $file->loudnessGainDb() : null;

            $entries[] = [
                'name' => self::fileName($file),
                'bytes' => $size,
                'amplify' => $gainDb !== null ? "{$gainDb} dB" : null,
                'url' => SignedDeliveryUrl::for('liquidsoap.media', [
                    'slug' => $station->slug,
                    'mediaFile' => $file->id,
                ]),
            ];
        }

        return $entries;
    }
}
