<?php

namespace App\Jobs;

use App\Models\Station;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateDailyRundownsJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Carbon|null  $targetDate  Datum für das generiert werden soll (Standard: morgen)
     * @param  int|null  $stationId  Nur für diese Station generieren (null = alle Stationen)
     */
    public function __construct(
        public readonly ?Carbon $targetDate = null,
        public readonly ?int $stationId = null,
        public readonly bool $force = false,
    ) {}

    public function handle(RundownGeneratorService $generator): void
    {
        // Carbon\Carbon sicherstellen (nicht Immutable) – der Generator mutiert den Cursor in-place.
        $date = $this->targetDate ?? Carbon::now()->addDay()->startOfDay();
        // 0=Mo...6=So (Carbon: 1=Mo...7=So → -1)
        $weekday = ($date->dayOfWeekIso - 1);

        $stations = $this->stationId
            ? Station::where('id', $this->stationId)->get()
            : Station::all();

        foreach ($stations as $station) {
            // Stationen mit aktivierter Option lassen ihre Rundowns nachts bewusst neu
            // generieren (z.B. um frische Musik-Uploads einzubeziehen) – auch wenn der
            // Lauf nicht global geforced wurde. Bereits gespielte Rundowns bleiben geschützt.
            $force = $this->force || $station->regenerate_rundowns_nightly;

            $slots = $station->hourGridSlots()
                ->where('weekday', $weekday)
                ->with('playlist.items.mediaFile')
                ->orderBy('hour')
                ->get();

            foreach ($slots as $slot) {
                try {
                    $generator->generate($station, $slot, $date, $force);
                } catch (\RuntimeException $e) {
                    // played-Rundowns überspringen – kein Fehler loggen
                } catch (\Throwable $e) {
                    Log::error("Rundown-Generierung fehlgeschlagen: Station #{$station->id}, {$date->toDateString()} {$slot->hour}:00 – {$e->getMessage()}");
                }
            }
        }
    }
}
