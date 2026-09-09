<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\LiquidsoapCommandService;
use App\Services\LiquidsoapStateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radioring:enforce-hard-starts')]
#[Description('Forces the switch to hard-start rundowns on the hour (sample accurate, via skip)')]
class EnforceHardStarts extends Command
{
    public function handle(LiquidsoapStateService $state, LiquidsoapCommandService $commands): int
    {
        // Nur Stationen mit laufendem Container betrachten.
        $stations = Station::whereHas('stream', fn ($q) => $q->where('status', 'running'))->get();

        foreach ($stations as $station) {
            // Preferred path: announce the cut before the full hour so the container can
            // fade the running track out into it instead of after it.
            if ($upcoming = $state->upcomingHardStart($station)) {
                $lead = $state->secondsUntilStart($upcoming);

                $state->announceHardStart($station, $upcoming);
                $commands->skip($station, $lead);

                $this->info(__('Hard start announced: station #:station, rundown #:rundown at :hour, cut in :lead s', [
                    'station' => $station->id,
                    'rundown' => $upcoming->id,
                    'hour' => sprintf('%02d:00', $upcoming->broadcast_hour),
                    'lead' => round($lead),
                ]));

                continue;
            }

            $hard = $state->pendingHardStart($station);

            if (! $hard) {
                continue;
            }

            // Cursor auf den Hard-Rundown setzen, dann sofort skippen → Liquidsoap
            // zieht /next und bekommt Track 0 des Hard-Rundowns.
            $state->commitHardStart($station, $hard);
            $commands->skip($station);

            $this->info(__('Hard start forced: station #:station, rundown #:rundown at :hour', [
                'station' => $station->id,
                'rundown' => $hard->id,
                'hour' => sprintf('%02d:00', $hard->broadcast_hour),
            ]));
        }

        return self::SUCCESS;
    }
}
