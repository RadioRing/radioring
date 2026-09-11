<?php

namespace App\Support\PlaylistElements;

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Models\Playlist;
use App\Services\AudioMetadataService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file uploaded straight from the editor.
 *
 * It takes exactly the same route as an upload through the media library: it lands in
 * the tenant library, its ID3 tags are read, and its loudness is measured offline.
 * Anything else would put a track on air that is never normalised.
 */
class UploadElement implements PlaylistElementType
{
    public function __construct(private readonly string $type) {}

    public function rules(): array
    {
        return [
            'newTitle' => 'required|string|min:1|max:200',
            'newFile' => 'required|file|mimes:mp3,m4a,ogg,wav,flac|max:307200',
        ];
    }

    public function create(Playlist $playlist, ElementDraft $draft, int $position): int
    {
        $station = $playlist->station;
        $original = $draft->upload->getClientOriginalName();
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $filename = Str::random(8).'_'.Str::slug(pathinfo($original, PATHINFO_FILENAME)).'.'.$extension;

        $filePath = $draft->upload->storeAs("tenants/{$station->tenant_id}/media", $filename, 'local');

        $metadata = app(AudioMetadataService::class)->read(
            Storage::disk('local')->path($filePath)
        );

        $mediaFile = $station->mediaFiles()->create([
            'title' => $draft->title,
            'artist' => $metadata['artist'],
            'album' => $metadata['album'],
            'type' => $this->type,
            'file_path' => $filePath,
            'duration_seconds' => $metadata['duration'],
        ]);

        // Lautheit offline (per ffmpeg) messen – einmalig, asynchron.
        AnalyzeMediaLoudnessJob::dispatch($mediaFile->id);

        $playlist->items()->create([
            'position' => $position,
            'type' => $this->type,
            'title' => $draft->title,
            'media_file_id' => $mediaFile->id,
            'relative_offset_seconds' => $draft->relativeOffsetSeconds,
        ]);

        return 1;
    }
}
