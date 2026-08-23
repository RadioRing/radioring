<?php

namespace App\Http\Controllers;

use App\Models\GeneratedPlaylistItem;
use App\Models\MediaFile;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiquidsoapMediaController extends Controller
{
    /**
     * Liefert eine Mediendatei für Liquidsoap aus.
     * Auth ueber eine signierte URL (siehe LiquidsoapNextTrackController).
     */
    public function __invoke(Request $request, string $slug, int $mediaFile): StreamedResponse
    {
        // Signierte URL statt api_token im Query-String: eine Signatur gilt nur fuer
        // genau diese Datei und laeuft ab, waehrend der Token unbegrenzt vollen
        // API-Zugriff gaebe. Relativ geprueft, damit interner und oeffentlicher Host
        // gleichermaßen funktionieren.
        abort_unless($request->hasValidRelativeSignature(), 401);

        $station = Station::where('slug', $slug)->firstOrFail();

        $file = MediaFile::findOrFail($mediaFile);

        // Die Station darf nur eigene oder von ihr verlinkte Dateien ausliefern.
        abort_unless($station->canUseMedia($file), 403);

        $path = $this->pathForItem($request, $station->id, $file) ?? $file->file_path;

        abort_unless(Storage::disk('local')->exists($path), 404);

        $fullPath = Storage::disk('local')->path($path);
        $filename = basename($path);
        $size = filesize($fullPath);

        return response()->stream(function () use ($fullPath) {
            $handle = fopen($fullPath, 'rb');
            while (! feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        }, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => $size,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Der am Rundown-Item eingefrorene Pfad, wenn die Mediendatei seit der Generierung
     * ersetzt wurde. So spielt ein bereits geladener Rundown die alte Fassung zu Ende,
     * waehrend neu generierte Rundowns die neue Datei bekommen.
     */
    private function pathForItem(Request $request, int $stationId, MediaFile $file): ?string
    {
        $itemId = $request->integer('item');

        if (! $itemId) {
            return null;
        }

        $item = GeneratedPlaylistItem::whereKey($itemId)
            ->where('media_file_id', $file->id)
            ->whereHas('generatedPlaylist', fn ($q) => $q->where('station_id', $stationId))
            ->with('mediaFile')
            ->first();

        return $item?->supersededPath();
    }
}
