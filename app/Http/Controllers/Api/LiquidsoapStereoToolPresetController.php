<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Support\StereoToolPresetLibrary;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the .sts preset a station selected to its own container, shipped and uploaded
 * ones alike, so the station image needs no presets of its own.
 *
 * 404 is a normal answer, not a fault: nothing selected, or the selection no longer
 * resolves. The entrypoint then starts Liquidsoap without a preset.
 */
class LiquidsoapStereoToolPresetController extends Controller
{
    public function __invoke(string $slug): BinaryFileResponse
    {
        $station = Station::where('slug', $slug)->firstOrFail();

        abort_unless($station->stereoToolActive(), 404);

        $path = StereoToolPresetLibrary::resolve($station);

        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-store',
        ]);
    }
}
