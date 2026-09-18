<?php

namespace App\Jobs;

use App\Exceptions\RundownAlreadyPlayedException;
use App\Models\GeneratedPlaylist;
use App\Models\HourGridSlot;
use App\Models\Station;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PreloadNextRundownJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Releases the uniqueness lock even if the worker dies mid-run, so a crash cannot
     * silence the preloading for good.
     */
    public int $uniqueFor = 900;

    /**
     * @param  int|null  $stationId  Nur für diese Station (null = alle Stationen)
     * @param  int|null  $horizonHours  wie weit voraus geprüft wird (null = Konfiguration)
     */
    public function __construct(
        public readonly ?int $stationId = null,
        public readonly ?int $horizonHours = null,
    ) {}

    /**
     * Makes sure every active station has a playable rundown for the hours just ahead.
     */
    public function handle(RundownGeneratorService $generator): void
    {
        $horizon = $this->horizonHours ?? (int) config('radioring.rundown_preload_horizon_hours', 3);

        $stations = $this->stationId
            ? Station::where('id', $this->stationId)->get()
            : Station::where('status', 'active')->get();

        $currentHour = Carbon::now()->startOfHour();

        foreach ($stations as $station) {
            for ($offset = 0; $offset <= $horizon; $offset++) {
                $target = $currentHour->copy()->addHours($offset);

                if ($this->hasReadyRundown($station, $target)) {
                    continue;
                }

                $weekday = $target->dayOfWeekIso - 1; // 0=Mo ... 6=So

                /** @var HourGridSlot|null $slot */
                $slot = $station->hourGridSlots()
                    ->where('weekday', $weekday)
                    ->where('hour', $target->hour)
                    ->with('playlist.items.mediaFile')
                    ->first();

                if (! $slot) {
                    // Keine Playlist für diesen Slot – kein Rundown nötig
                    continue;
                }

                try {
                    $generator->generate($station, $slot, $target->copy()->startOfDay(), force: false);
                    Log::info("PreloadNextRundownJob: Rundown nachgezogen – Station #{$station->id}, {$target->format('Y-m-d H:00')}");
                } catch (RundownAlreadyPlayedException $e) {
                    // played-Rundowns überspringen (die laufende Stunde kann das treffen)
                } catch (\Throwable $e) {
                    Log::error("PreloadNextRundownJob fehlgeschlagen: Station #{$station->id}, {$target->format('Y-m-d H:00')} – {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Is this hour already covered? Kept deliberately narrow so the common case - every
     * hour in the horizon is fine - stays one indexed query per hour.
     */
    private function hasReadyRundown(Station $station, Carbon $target): bool
    {
        return GeneratedPlaylist::where('station_id', $station->id)
            ->whereDate('broadcast_date', $target->toDateString())
            ->where('broadcast_hour', $target->hour)
            ->whereIn('status', ['ready', 'played'])
            ->exists();
    }
}
