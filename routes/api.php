<?php

use App\Http\Controllers\Api\LiquidsoapConnectController;
use App\Http\Controllers\Api\LiquidsoapLiveController;
use App\Http\Controllers\Api\LiquidsoapNextTrackController;
use App\Http\Controllers\Api\LiquidsoapNowPlayingController;
use App\Http\Controllers\Api\LiquidsoapScriptController;
use App\Http\Controllers\LiquidsoapMediaController;
use App\Http\Controllers\LiquidsoapPreparedController;
use App\Http\Middleware\LiquidsoapTokenAuth;
use Illuminate\Support\Facades\Route;

// Liquidsoap Pull-API – Auth via Bearer stations.api_token
Route::middleware(LiquidsoapTokenAuth::class)
    ->prefix('liquidsoap/{slug}')
    ->group(function () {
        Route::get('/next', LiquidsoapNextTrackController::class)
            ->name('liquidsoap.next');

        Route::post('/now-playing', LiquidsoapNowPlayingController::class)
            ->name('liquidsoap.now-playing');

        Route::post('/connect', LiquidsoapConnectController::class)
            ->name('liquidsoap.connect');

        Route::post('/live', LiquidsoapLiveController::class)
            ->name('liquidsoap.live');

        Route::get('/script', LiquidsoapScriptController::class)
            ->name('liquidsoap.script');
    });

// Mediendatei-Auslieferung – Auth via ?token= Query-Parameter, adressiert per Medien-ID
// (eine Station kann fremde, verlinkte Dateien senden – der Slug-/Dateiname-Pfad reicht
// dafür nicht mehr als Schlüssel).
Route::get('/stream/media/{slug}/{mediaFile}', LiquidsoapMediaController::class)
    ->name('liquidsoap.media')
    ->whereNumber('mediaFile');

// Vorbereitete (lokal gecachte) Kopie eines dynamischen externen Items, adressiert per
// generated_playlist_item-ID. Auth via ?token=.
Route::get('/stream/prepared/{slug}/{item}', LiquidsoapPreparedController::class)
    ->name('liquidsoap.prepared')
    ->whereNumber('item');
