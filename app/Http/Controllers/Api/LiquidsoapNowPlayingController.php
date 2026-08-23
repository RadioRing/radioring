<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use App\Services\LiquidsoapStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiquidsoapNowPlayingController extends Controller
{
    public function __invoke(Request $request, string $slug, LiquidsoapStateService $stateService): JsonResponse
    {
        $station = Station::where('slug', $slug)->firstOrFail();

        // Liquidsoap übergibt die annotierte Item-ID (zuverlässig) und den Dateinamen.
        $item = null;

        // 1. Bevorzugt über die annotierte Item-ID (eindeutig, auch bei Remote-URLs
        //    und über Rundowns hinweg doppelten Dateinamen).
        $itemId = $request->input('item_id');

        if (! empty($itemId)) {
            $item = GeneratedPlaylistItem::whereKey($itemId)
                ->whereHas('generatedPlaylist', fn ($q) => $q->where('station_id', $station->id))
                ->first();
        }

        // 2. Fallback: anhand des Dateinamens (ältere Scripts ohne Annotation).
        $filename = $request->input('filename', '');

        if (! $item && $filename) {
            $base = basename($filename);
            $state = $station->liquidsoapState;

            // 1. Bevorzugt im aktuell gepullten Rundown. Der Dateiname kann auch zu einer
            //    ersetzten, am Item eingefrorenen Fassung gehoeren.
            if ($state?->current_rundown_id) {
                $item = GeneratedPlaylistItem::where('generated_playlist_id', $state->current_rundown_id)
                    ->where(fn ($q) => $q->where('media_file_path', 'like', '%'.$base)
                        ->orWhereHas('mediaFile', fn ($q) => $q->where('file_path', 'like', '%'.$base)))
                    ->first();
            }

            // 2. Fallback: in den heutigen Rundowns der Station (der Pull-Cursor kann
            //    durch Prefetch schon im nächsten Rundown stehen, während noch ein
            //    Track aus dem vorigen läuft).
            if (! $item) {
                $item = GeneratedPlaylistItem::whereHas('generatedPlaylist', fn ($q) => $q->where('station_id', $station->id)->whereDate('broadcast_date', today()))
                    ->where(fn ($q) => $q->where('media_file_path', 'like', '%'.$base)
                        ->orWhereHas('mediaFile', fn ($q) => $q->where('file_path', 'like', '%'.$base)))
                    ->latest('id')
                    ->first();
            }
        }

        // Live-Übernahme erkennen: kein item_id auflösbar, aber es kommen Metadaten
        // (Titel/Interpret) herein → ein externer Encoder sendet über den input.harbor.
        $title = trim((string) $request->input('title', ''));
        $artist = trim((string) $request->input('artist', ''));

        if (! $item && empty($itemId) && ($title !== '' || $artist !== '')) {
            [$artist, $title] = $this->splitArtistTitle($title, $artist);

            $stateService->setLive($station, $title !== '' ? $title : null, $artist !== '' ? $artist : null);

            return response()->json(['ok' => true, 'live' => true]);
        }

        $stateService->setNowPlaying($station, $item);

        return response()->json(['ok' => true]);
    }

    /**
     * Trennt „Interpret - Titel" auf. Live-Encoder senden den Streamtitel oft als ein
     * kombiniertes title-Feld ohne separaten Interpreten – dann am ersten " - " teilen.
     * Liegt bereits ein Interpret vor oder fehlt der Trenner, bleibt alles unverändert.
     *
     * @return array{0: string, 1: string} [Interpret, Titel]
     */
    private function splitArtistTitle(string $title, string $artist): array
    {
        if ($artist !== '' || ! str_contains($title, ' - ')) {
            return [$artist, $title];
        }

        [$splitArtist, $splitTitle] = explode(' - ', $title, 2);

        return [trim($splitArtist), trim($splitTitle)];
    }
}
