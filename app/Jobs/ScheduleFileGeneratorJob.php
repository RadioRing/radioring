<?php

namespace App\Jobs;

use App\Models\GeneratedPlaylist;
use App\Models\Station;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScheduleFileGeneratorJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $stationId = null,
    ) {}

    public function handle(): void
    {
        $stations = $this->stationId
            ? Station::where('id', $this->stationId)->get()
            : Station::where('status', 'active')->get();

        foreach ($stations as $station) {
            $this->writeScheduleFile($station);
        }
    }

    private function writeScheduleFile(Station $station): void
    {
        $now = now();
        $rundown = GeneratedPlaylist::where('station_id', $station->id)
            ->where('broadcast_date', $now->toDateString())
            ->where('broadcast_hour', $now->hour)
            ->whereIn('status', ['ready', 'played'])
            ->with('items.mediaFile')
            ->first();

        $slug = $station->slug;
        $path = "stations/{$slug}/schedule.liq";

        if (! $rundown || $rundown->items->isEmpty()) {
            // Kein Rundown → leere Playlist (Liquidsoap fällt auf Silence zurück)
            Storage::disk('local')->put($path, "# Kein Rundown für {$now->toDateTimeString()} – Silence\n");

            return;
        }

        $adbreakSignalPath = config('radioring.adbreak_signal_path', '/opt/ad_break.mp3');
        $newsSignalPath = config('radioring.news_signal_path', '');

        $lines = [];
        foreach ($rundown->items as $item) {
            if ($item->source_type === 'adbreak') {
                $lines[] = "annotate:title=\"START_AD_BREAK\",artist=\" \":{$adbreakSignalPath}";

                continue;
            }

            if ($item->source_type === 'news') {
                if ($newsSignalPath) {
                    $lines[] = "annotate:title=\"Nachrichten\",artist=\" \":{$newsSignalPath}";
                }

                continue;
            }

            if (! $item->mediaFile) {
                continue;
            }

            // Absoluter Pfad für Liquidsoap im Container (wird in Phase 4 gemountet).
            // Wurde die Datei nach der Generierung ersetzt, bleibt es bei der eingefrorenen
            // Fassung – dieser Rundown spielt sie zu Ende.
            $relativePath = $item->supersededPath() ?? $item->mediaFile->file_path;
            $filePath = storage_path("app/{$relativePath}");
            $lines[] = $filePath;
        }

        Storage::disk('local')->put($path, implode("\n", $lines)."\n");

        Log::debug("schedule.liq geschrieben: {$slug}, {$now->toDateTimeString()}, ".count($lines).' Tracks');
    }
}
