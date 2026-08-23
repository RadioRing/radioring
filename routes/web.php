<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\MediaPreviewController;
use App\Http\Controllers\MediaUploadController;
use App\Livewire\Admin\InviteCodes as AdminInviteCodes;
use App\Livewire\Admin\Settings as AdminSettings;
use App\Livewire\Admin\Stations as AdminStations;
use App\Livewire\Admin\Users as AdminUsers;
use App\Livewire\Dashboard;
use App\Livewire\ExternalSource\Index as ExternalSourceIndex;
use App\Livewire\Help\Index as HelpIndex;
use App\Livewire\HourGrid\Index as HourGridIndex;
use App\Livewire\MediaLibrary\Index as MediaLibraryIndex;
use App\Livewire\MediaLibrary\Show as MediaLibraryShow;
use App\Livewire\Output\Index as OutputIndex;
use App\Livewire\Playlist\Index as PlaylistIndex;
use App\Livewire\Playlist\Manager as PlaylistManager;
use App\Livewire\Protocol\Index as ProtocolIndex;
use App\Livewire\Rundown\Show as RundownShow;
use App\Livewire\Station\Create as StationCreate;
use App\Livewire\Station\Edit as StationEdit;
use App\Livewire\Station\Select as StationSelect;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('stations', StationSelect::class)->name('station.select');
    Route::livewire('stations/create', StationCreate::class)->name('station.create');
    Route::livewire('stations/{station}/edit', StationEdit::class)->name('station.edit');

    Route::livewire('playlists', PlaylistIndex::class)->name('playlist.index');
    Route::livewire('playlists/{playlist}', PlaylistManager::class)->name('playlist.manager');

    Route::livewire('media', MediaLibraryIndex::class)->name('media.index');
    Route::post('/media/upload-chunk', [MediaUploadController::class, 'store'])->name('media.upload.chunk');
    Route::get('/media/{mediaFile}/preview', MediaPreviewController::class)->name('media.preview');
    Route::livewire('media/{mediaFile}', MediaLibraryShow::class)->name('media.show')->whereNumber('mediaFile');

    Route::livewire('externe-quellen', ExternalSourceIndex::class)->name('external-source.index');

    Route::livewire('raster', HourGridIndex::class)->name('hour-grid.index');
    Route::livewire('rundown/{date}/{hour}', RundownShow::class)->name('rundown.show');

    Route::livewire('outputs', OutputIndex::class)->name('output.index');
    Route::livewire('protokoll', ProtocolIndex::class)->name('protocol.index');

    Route::livewire('hilfe', HelpIndex::class)->name('help.index');

    // Impersonation beenden – muss der gerade impersonierte (nicht-Admin) Nutzer dürfen.
    Route::post('impersonate/leave', [ImpersonationController::class, 'stop'])->name('admin.impersonate.leave');
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->group(function () {
    Route::livewire('users', AdminUsers::class)->name('admin.users');
    Route::livewire('stations', AdminStations::class)->name('admin.stations');
    Route::livewire('invite-codes', AdminInviteCodes::class)->name('admin.invite-codes');
    Route::livewire('settings', AdminSettings::class)->name('admin.settings');

    // Der Betriebsmodus wird im Controller geprüft, nicht bei der Registrierung: er ist
    // zur Laufzeit umschaltbar, Routen werden aber beim Start gecacht.
    Route::post('impersonate/{user}', [ImpersonationController::class, 'start'])->name('admin.impersonate');
});

require __DIR__.'/settings.php';
