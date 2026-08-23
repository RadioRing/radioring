<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Services\LiquidsoapScriptGenerator;
use Illuminate\Http\Response;

class LiquidsoapScriptController extends Controller
{
    public function __invoke(string $slug, LiquidsoapScriptGenerator $generator): Response
    {
        $station = Station::where('slug', $slug)->firstOrFail();

        return response($generator->generate($station), 200)
            ->header('Content-Type', 'text/plain');
    }
}
