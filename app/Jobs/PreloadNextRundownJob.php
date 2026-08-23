<?php

namespace App\Jobs;

use App\Models\HourGridSlot;
use App\Models\Station;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PreloadNextRundownJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $stationId  Nur für diese Station (null = alle Stationen)
     */
    public function __construct(
        public readonly ?int $stationId = null,
    ) {}

    /**
     * Stellt sicher, dass der Rundown der nächsten vollen Stunde für alle aktiven
     * Stationen im Status "ready" ist, bevor Liquidsoap ihn abruft.
     *
     * Läuft jede Stunde um :55 – gibt 5 Minuten Vorlauf.
     */
    public function handle(RundownGeneratorService $generator): void
    {
        // Nächste volle Stunde berechnen (Carbon\Carbon sicherstellen, nicht Immutable)
        $nextHour = Carbon::now()->addHour()->startOfHour();
        $weekday = $nextHour->dayOfWeekIso - 1; // 0=Mo ... 6=So

        $stations = $this->stationId
            ? Station::where('id', $this->stationId)->get()
            : Station::where('status', 'active')->get();

        foreach ($stations as $station) {
            /** @var HourGridSlot|null $slot */
            $slot = $station->hourGridSlots()
                ->where('weekday', $weekday)
                ->where('hour', $nextHour->hour)
                ->with('playlist.items.mediaFile')
                ->first();

            if (! $slot) {
                // Keine Playlist für diesen Slot – kein Rundown nötig
                continue;
            }

            try {
                $generator->generate($station, $slot, $nextHour->copy()->startOfDay(), force: false);
                Log::info("PreloadNextRundownJob: Rundown bereit – Station #{$station->id}, {$nextHour->format('Y-m-d H:00')}");
            } catch (\RuntimeException $e) {
                // played-Rundowns überspringen (sollte hier nicht vorkommen)
            } catch (\Throwable $e) {
                Log::error("PreloadNextRundownJob fehlgeschlagen: Station #{$station->id}, {$nextHour->format('Y-m-d H:00')} – {$e->getMessage()}");
            }
        }
    }
}
