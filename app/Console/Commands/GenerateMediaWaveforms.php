<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMediaWaveformJob;
use App\Models\MediaFile;
use App\Models\Station;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('media:generate-waveforms
    {--station= : Only files of this station\'s library (ID or slug)}
    {--force : Generate files that already have a waveform again}
    {--sync : Generate right away instead of going through the queue}')]
#[Description('Precomputes the waveform peaks of existing media files with ffmpeg')]
class GenerateMediaWaveforms extends Command
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

        if (! $this->option('force')) {
            $query->whereNull('waveform_path');
        }

        $count = 0;

        $query->orderBy('id')->chunkById(100, function ($files) use (&$count) {
            foreach ($files as $file) {
                if ($this->option('sync')) {
                    GenerateMediaWaveformJob::dispatchSync($file->id);
                    $this->line('  '.__('generated: #:id :title', ['id' => $file->id, 'title' => $file->title]));
                } else {
                    GenerateMediaWaveformJob::dispatch($file->id);
                }

                $count++;
            }
        });

        $this->info($this->option('sync')
            ? __(':count waveform(s) generated.', ['count' => $count])
            : __(':count file(s) queued for waveform generation.', ['count' => $count]));

        return self::SUCCESS;
    }
}
