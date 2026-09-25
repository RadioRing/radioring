<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Services\LiquidsoapStateService;
use App\Support\EmergencyLoop;
use Illuminate\Http\JsonResponse;

/**
 * Tells the station container which emergency files to hold and where to get them.
 *
 * The container syncs by file name, so an empty list is a valid answer and means: drop
 * what you have.
 */
class LiquidsoapEmergencyController extends Controller
{
    public function __invoke(string $slug, LiquidsoapStateService $stateService): JsonResponse
    {
        $station = Station::where('slug', $slug)->firstOrFail();

        $stateService->markEmergencySynced($station);

        return response()->json(['files' => EmergencyLoop::manifest($station)]);
    }
}
