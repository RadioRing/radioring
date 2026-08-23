<?php

namespace App\Console\Commands;

use App\Models\GeneratedPlaylistItem;
use App\Models\MediaFileVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('media:prune-replaced {--days=7 : Ersetzte Fassungen älter als N Tage löschen} {--dry-run : Nur anzeigen, nichts löschen}')]
#[Description('Löscht ersetzte Fassungen von Mediendateien, auf die kein Rundown mehr zeigt.')]
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
            ? "Würde löschen: {$pruned} ersetzte Fassung(en), behalten: {$kept}."
            : "Gelöscht: {$pruned} ersetzte Fassung(en), behalten: {$kept}.");

        return self::SUCCESS;
    }
}
