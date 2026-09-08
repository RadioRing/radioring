<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupDownloadController extends Controller
{
    /**
     * Hands out a backup archive.
     *
     * Only reachable behind the admin middleware. The archive is a complete set of keys
     * to the installation, so there is deliberately no signed public link: whoever
     * downloads it has to be a logged-in administrator at that moment.
     */
    public function __invoke(Backup $backup): StreamedResponse
    {
        abort_unless($backup->isDownloadable(), 404);

        $path = $backup->path();

        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $backup->filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
