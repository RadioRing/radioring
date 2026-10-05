<?php

namespace App\Jobs;

use App\Models\MediaFile;
use App\Services\AudioMetadataService;
use App\Services\AudioTagWriterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the panel's title, artist and album back into the media file.
 *
 * The values are read when the job runs, not when it was queued, so a burst of edits ends
 * with the last one in the file. Files whose tags already match are left untouched.
 */
class WriteMediaTagsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Rewriting a long file takes a moment of disk I/O; it shares the long running queue
     * with the loudness analysis.
     */
    public function __construct(public readonly int $mediaFileId)
    {
        $this->onConnection('media');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('media-tags:'.$this->mediaFileId))->releaseAfter(30)];
    }

    public function handle(AudioMetadataService $reader, AudioTagWriterService $writer): void
    {
        $file = MediaFile::find($this->mediaFileId);

        if (! $file) {
            return;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($file->file_path)) {
            return;
        }

        $path = $disk->path($file->file_path);

        if (! $writer->supports($path)) {
            return;
        }

        $wanted = [
            'title' => $file->title,
            'artist' => $file->artist,
            'album' => $file->album,
        ];

        $current = $reader->read($path);

        if ($this->matches($current, $wanted)) {
            return;
        }

        $writer->write($path, $wanted);
    }

    /**
     * @param  array{title: ?string, artist: ?string, album: ?string, duration: ?int}  $current
     * @param  array{title: ?string, artist: ?string, album: ?string}  $wanted
     */
    private function matches(array $current, array $wanted): bool
    {
        foreach ($wanted as $key => $value) {
            if (trim((string) $current[$key]) !== trim((string) $value)) {
                return false;
            }
        }

        return true;
    }
}
