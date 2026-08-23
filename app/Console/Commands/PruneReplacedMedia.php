<?php

namespace App\Console\Commands;

use App\Models\GeneratedPlaylistItem;
use App\Models\MediaFileVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('media:prune-replaced {--days=7 : Delete replaced versions older than N days} {--dry-run : Only show, delete nothing}')]
#[Description('Deletes replaced versions of media files that no rundown points at any more.')]
class PruneReplacedMedia extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('local');

        $candidates = MediaFileVersion::where('created_at', '<', now()->subDays($days))->get();
        $pruned = 0;
        $kept = 0;

        foreach ($candidates as $version) {
            // Rundowns halten den Pfad als Snapshot. Solange einer davon noch nicht
            // gesendet ist, muss die Fassung liegen bleiben – er spielt sie zu Ende.
            $stillScheduled = GeneratedPlaylistItem::where('media_file_path', $version->file_path)
                ->whereHas('generatedPlaylist', fn ($q) => $q->where('status', '!=', 'played'))
                ->exists();

            if ($stillScheduled) {
                $kept++;

                continue;
            }

            if (! $dryRun) {
                $disk->delete($version->file_path);
                $version->delete();
            }

            $pruned++;
        }

        $this->info($dryRun
            ? __('Would delete :pruned replaced version(s), keeping :kept.', ['pruned' => $pruned, 'kept' => $kept])
            : __('Deleted :pruned replaced version(s), kept :kept.', ['pruned' => $pruned, 'kept' => $kept]));

        return self::SUCCESS;
    }
}
