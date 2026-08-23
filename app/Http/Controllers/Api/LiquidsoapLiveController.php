<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Services\LiquidsoapStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Meldet den Verbindungsstatus des Live-Eingangs (input.harbor on_connect/on_disconnect)
 * an RadioRing – unabhängig von eingehenden Metadaten, damit eine laufende Live-Sendung
 * sofort und zuverlässig erkannt wird.
 */
class LiquidsoapLiveController extends Controller
{
    public function __invoke(Request $request, string $slug, LiquidsoapStateService $stateService): JsonResponse
    {
        $station = Station::where('slug', $slug)->firstOrFail();

        $stateService->setLiveConnected($station, $request->boolean('connected'));

        return response()->json(['ok' => true]);
    }
}
