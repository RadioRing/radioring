<?php

namespace App\Jobs;

use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hands out one GenerateRundownJob per station and broadcast hour.
 *
 * This job only fans out; the work itself happens per hour. The hours of a station are
 * dispatched in order, so a worker picks them up in broadcast order and the rotation
 * history of the preceding hours is in place when the next one is built.
 */
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

    public function handle(): void
    {
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
                ->orderBy('hour')
                ->get();

            foreach ($slots as $slot) {
                GenerateRundownJob::dispatch($station->id, $slot->id, $date->toDateString(), $force);
            }
        }
    }
}
