<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaPreviewController extends Controller
{
    /**
     * Audio-MIME-Typen je Dateiendung für das Vorhören im Browser.
     *
     * @var array<string, string>
     */
    private const MIME_TYPES = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
    ];

    /**
     * Streams a media file for preview. BinaryFileResponse handles range requests (seeking).
     */
    public function __invoke(Request $request, MediaFile $mediaFile): BinaryFileResponse
    {
        abort_unless($request->user()->can('view', $mediaFile), 403);

        abort_unless(Storage::disk('local')->exists($mediaFile->file_path), 404);

        $extension = strtolower(pathinfo($mediaFile->file_path, PATHINFO_EXTENSION));

        return response()->file(Storage::disk('local')->path($mediaFile->file_path), [
            'Content-Type' => self::MIME_TYPES[$extension] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
