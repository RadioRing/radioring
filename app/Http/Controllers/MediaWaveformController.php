<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaWaveformController extends Controller
{
    /**
     * Serves the precomputed waveform peaks of a media file as JSON. The URL carries a
     * hash of the file path, so a replaced file never hits a cached waveform.
     */
    public function __invoke(Request $request, MediaFile $mediaFile): BinaryFileResponse
    {
        abort_unless($request->user()->can('view', $mediaFile), 403);

        abort_if($mediaFile->waveform_path === null, 404);
        abort_unless(Storage::disk('local')->exists($mediaFile->waveform_path), 404);

        return response()->file(Storage::disk('local')->path($mediaFile->waveform_path), [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
