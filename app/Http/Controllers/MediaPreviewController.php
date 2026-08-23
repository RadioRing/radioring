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
     * Streamt eine Mediendatei zum Vorhören in der Medienbibliothek.
     *
     * Liefert eine BinaryFileResponse, die Range-Anfragen (Springen/Seeking im
     * Audio-Player) automatisch unterstützt. Zugriff hat der angemeldete Nutzer
     * nur auf Dateien, die seine aktuelle Station nutzen darf oder die zu einer
     * für ihn zugänglichen Station gehören (z. B. beim Durchstöbern vor dem Verlinken).
     */
    public function __invoke(Request $request, MediaFile $mediaFile): BinaryFileResponse
    {
        $user = $request->user();
        $station = $user->currentStation();

        $mayAccess = ($station !== null && $station->canUseMedia($mediaFile))
            || $user->accessibleStations()->whereKey($mediaFile->station_id)->exists();

        abort_unless($mayAccess, 403);

        abort_unless(Storage::disk('local')->exists($mediaFile->file_path), 404);

        $extension = strtolower(pathinfo($mediaFile->file_path, PATHINFO_EXTENSION));

        return response()->file(Storage::disk('local')->path($mediaFile->file_path), [
            'Content-Type' => self::MIME_TYPES[$extension] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
