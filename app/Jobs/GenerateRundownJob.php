<?php

namespace App\Jobs;

use App\Exceptions\RundownAlreadyPlayedException;
use App\Models\HourGridSlot;
use App\Models\Station;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Generates the rundown of a single broadcast hour.
 *
 * One hour per job on purpose. Building a whole day in one go took long enough to run
 * into the worker timeout, and a job killed that way took the hours it had not reached
 * yet down with it: for a station that regenerates nightly every retry started over at
 * hour 0 and never got any further than the first attempt. A single hour is a matter of
 * seconds, a retry costs nothing, and a failure stays inside its hour.
 */
class GenerateRundownJob implements ShouldQueue
{
    use Queueable;

    /** Retry twice, quickly: a rundown that is missing is missing on air. */
    public array $backoff = [10, 60];

    /**
     * @param  string  $broadcastDate  broadcast day as Y-m-d; passed as a string so the
     *                                 queue payload carries no serialised Carbon
     */
    public function __construct(
        public readonly int $stationId,
        public readonly int $slotId,
        public readonly string $broadcastDate,
        public readonly bool $force = false,
    ) {}

    public function handle(RundownGeneratorService $generator): void
    {
        $station = Station::find($this->stationId);
        $slot = HourGridSlot::with('playlist.items.mediaFile')->find($this->slotId);

        // The grid may have been edited between dispatch and execution.
        if (! $station || ! $slot || $slot->station_id !== $this->stationId || ! $slot->playlist) {
            return;
        }

        try {
            $generator->generate($station, $slot, Carbon::parse($this->broadcastDate)->startOfDay(), $this->force);
        } catch (RundownAlreadyPlayedException $e) {
            // Already played: not an error, and a retry would not change it.
            Log::debug("Rundown uebersprungen: Station #{$this->stationId}, {$this->broadcastDate} {$slot->hour}:00 – {$e->getMessage()}");
        }
    }

    /**
     * Anything else is a real failure: let it bubble up so the queue retries it and a
     * job that stays broken ends up in failed_jobs instead of being swallowed.
     */
    public function failed(\Throwable $e): void
    {
        Log::error("Rundown-Generierung fehlgeschlagen: Station #{$this->stationId}, Slot #{$this->slotId}, {$this->broadcastDate} – {$e->getMessage()}");
    }
}
