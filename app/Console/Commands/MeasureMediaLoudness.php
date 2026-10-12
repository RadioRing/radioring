<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Models\MediaFile;
use App\Models\Station;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('media:measure-loudness
    {--station= : Only files of this station\'s library (ID or slug)}
    {--force : Measure files that already carry a value again}
    {--cue-points : Also set cue points from silence where a file has none yet}
    {--sync : Measure right away instead of going through the queue}')]
#[Description('Measures the loudness (EBU R128) of existing media files with ffmpeg and optionally suggests cue points from silence')]
class MeasureMediaLoudness extends Command
{
    public function handle(): int
    {
        $query = MediaFile::query();

        if ($this->option('station') !== null) {
            $station = Station::where('id', $this->option('station'))
                ->orWhere('slug', $this->option('station'))
                ->first();

            if (! $station) {
                $this->error(__('No station found for :station.', ['station' => $this->option('station')]));

                return self::FAILURE;
            }

            $query->where('tenant_id', $station->tenant_id);
        }

        $cuePoints = (bool) $this->option('cue-points');

        if (! $this->option('force')) {
            $query->where(function ($query) use ($cuePoints) {
                $query->whereNull('loudness_measured_at');

                if ($cuePoints) {
                    $query->orWhere(fn ($query) => $query->whereNull('cue_in_seconds')->whereNull('cue_out_seconds'));
                }
            });
        }

        $count = 0;

        $query->orderBy('id')->chunkById(100, function ($files) use (&$count, $cuePoints) {
            foreach ($files as $file) {
                if ($this->option('sync')) {
                    AnalyzeMediaLoudnessJob::dispatchSync($file->id, $cuePoints);
                    $this->line('  '.__('measured: #:id :title', ['id' => $file->id, 'title' => $file->title]));
                } else {
                    AnalyzeMediaLoudnessJob::dispatch($file->id, $cuePoints);
                }

                $count++;
            }
        });

        $this->info($this->option('sync')
            ? __(':count file(s) measured.', ['count' => $count])
            : __(':count file(s) queued for measuring.', ['count' => $count]));

        return self::SUCCESS;
    }
}
