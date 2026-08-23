<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('media:prune-chunks {--hours=2 : Delete chunk directories older than N hours}')]
#[Description('Deletes orphaned chunk upload directories older than N hours.')]
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

        $this->info(__('Deleted :count orphaned chunk directory/directories.', ['count' => $pruned]));

        return self::SUCCESS;
    }
}
