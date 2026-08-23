<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('media:prune-chunks {--hours=2 : Chunk-Verzeichnisse älter als N Stunden löschen}')]
#[Description('Löscht verwaiste Chunk-Upload-Verzeichnisse die älter als N Stunden sind.')]
class PruneChunks extends Command
{
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $threshold = now()->subHours($hours)->timestamp;
        $pruned = 0;

        foreach (Storage::disk('local')->directories('chunks') as $dir) {
            // Ersten Chunk als Zeitreferenz nehmen
            $files = Storage::disk('local')->files($dir);

            if (empty($files)) {
                Storage::disk('local')->deleteDirectory($dir);
                $pruned++;

                continue;
            }

            $lastModified = Storage::disk('local')->lastModified($files[0]);

            if ($lastModified && $lastModified < $threshold) {
                Storage::disk('local')->deleteDirectory($dir);
                $pruned++;
            }
        }

        $this->info("Gelöscht: {$pruned} verwaiste Chunk-Verzeichnis(se).");

        return self::SUCCESS;
    }
}
