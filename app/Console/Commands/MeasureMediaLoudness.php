<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Models\MediaFile;
use App\Models\Station;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('media:measure-loudness
    {--station= : Nur Dateien dieser Station (ID oder Slug)}
    {--force : Auch bereits gemessene Dateien neu messen}
    {--sync : Sofort messen statt über die Queue}')]
#[Description('Misst die Lautheit (EBU R128) vorhandener Mediendateien per ffmpeg nach und füllt media_files')]
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
                $this->error('Keine Station für „'.$this->option('station').'" gefunden.');

                return self::FAILURE;
            }

            $query->where('station_id', $station->id);
        }

        if (! $this->option('force')) {
            $query->whereNull('loudness_measured_at');
        }

        $count = 0;

        $query->orderBy('id')->chunkById(100, function ($files) use (&$count) {
            foreach ($files as $file) {
                if ($this->option('sync')) {
                    AnalyzeMediaLoudnessJob::dispatchSync($file->id);
                    $this->line("  gemessen: #{$file->id} {$file->title}");
                } else {
                    AnalyzeMediaLoudnessJob::dispatch($file->id);
                }

                $count++;
            }
        });

        $verb = $this->option('sync') ? 'gemessen' : 'in die Queue gestellt';
        $this->info("{$count} Datei(en) {$verb}.");

        return self::SUCCESS;
    }
}
