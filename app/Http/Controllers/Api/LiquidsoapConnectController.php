<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Services\LiquidsoapStateService;
use Illuminate\Http\JsonResponse;

/**
 * Wird vom Container-Entrypoint beim (Neu-)Start aufgerufen, bevor Liquidsoap
 * den ersten Track pullt. Setzt den vorausgeeilten Pull-Cursor auf den echten
 * Airplay-Stand zurück, damit nach einem Neustart keine Tracks übersprungen werden.
 */
class LiquidsoapConnectController extends Controller
{
    public function __invoke(string $slug, LiquidsoapStateService $stateService): JsonResponse
    {
        $station = Station::where('slug', $slug)->firstOrFail();

        $stateService->resumeFromAirplay($station);

        return response()->json(['ok' => true]);
    }
}
