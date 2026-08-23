<?php

namespace App\Jobs;

use App\Contracts\ContainerServiceInterface;
use App\Models\Station;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class StartStationContainer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Ein Kaltstart zieht erst das Station-Image (mehrere hundert MB). Job- und
     * Overlapping-Fenster muessen darueber liegen, sonst wird der Job erneut angestossen,
     * waehrend der erste Versuch noch laedt.
     */
    public int $timeout = 900;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public function __construct(public readonly int $stationId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('station-container:'.$this->stationId))->releaseAfter(900)];
    }

    public function handle(ContainerServiceInterface $containers): void
    {
        $station = Station::find($this->stationId);

        if (! $station) {
            Log::warning("StartStationContainer: Station #{$this->stationId} nicht gefunden.");

            return;
        }

        $stream = $station->ensureStream();
        $stream->update(['status' => 'starting']);

        $ok = $containers->startStationContainer($station);

        if ($ok) {
            $stream->update([
                'status' => 'running',
                'last_started_at' => now(),
            ]);

            Log::info("Station-Container gestartet: {$stream->container_name}");
        } else {
            $stream->update(['status' => 'error']);

            throw new \RuntimeException("Container-Start fehlgeschlagen: {$stream->container_name}");
        }
    }

    public function failed(\Throwable $exception): void
    {
        $station = Station::find($this->stationId);
        $station?->stream()->update(['status' => 'error']);
    }
}
