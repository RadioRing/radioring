<?php

namespace App\Http\Controllers;

use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiquidsoapPreparedController extends Controller
{
    /**
     * Liefert die lokal vorbereitete (heruntergeladene) Kopie eines dynamischen
     * externen Rundown-Items aus. Auth ueber eine signierte URL (siehe LiquidsoapNextTrackController).
     */
    public function __invoke(Request $request, string $slug, int $item): StreamedResponse
    {
        // Signierte URL statt api_token im Query-String: eine Signatur gilt nur fuer
        // genau diese Datei und laeuft ab, waehrend der Token unbegrenzt vollen
        // API-Zugriff gaebe. Relativ geprueft, damit interner und oeffentlicher Host
        // gleichermaßen funktionieren.
        abort_unless($request->hasValidRelativeSignature(), 401);

        $station = Station::where('slug', $slug)->firstOrFail();

        $generatedItem = GeneratedPlaylistItem::with('generatedPlaylist')->findOrFail($item);

        // Das Item muss zu einem Rundown dieser Station gehören.
        abort_unless($generatedItem->generatedPlaylist?->station_id === $station->id, 403);

        abort_unless(
            $generatedItem->prepared_path && Storage::disk('local')->exists($generatedItem->prepared_path),
            404
        );

        $fullPath = Storage::disk('local')->path($generatedItem->prepared_path);
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
            'Content-Disposition' => 'inline; filename="'.basename($fullPath).'"',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
