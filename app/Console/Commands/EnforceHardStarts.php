<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\LiquidsoapCommandService;
use App\Services\LiquidsoapStateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radioring:enforce-hard-starts')]
#[Description('Forces the cut to elements with a hard fixed time (sample accurate, via skip)')]
class EnforceHardStarts extends Command
{
    public function handle(LiquidsoapStateService $state, LiquidsoapCommandService $commands): int
    {
        // Nur Stationen mit laufendem Container betrachten.
        $stations = Station::whereHas('stream', fn ($q) => $q->where('status', 'running'))->get();

        foreach ($stations as $station) {
            // Announce ahead so the container fades out into the fixed time.
            if ($upcoming = $state->upcomingHardStart($station)) {
                $lead = $state->secondsUntilStart($upcoming);
                $state->announceHardStart($station, $upcoming);
                $commands->skip($station, $lead);

                $this->info(__('Hard start announced: station #:station, item #:item at :time, cut in :lead s', [
                    'station' => $station->id,
                    'item' => $upcoming->id,
                    'time' => $upcoming->fixed_at->format('H:i:s'),
                    'lead' => round($lead),
                ]));

                continue;
            }

            $hard = $state->pendingHardStart($station);

            if (! $hard) {
                continue;
            }

            // Missed announcement: move the cursor to the hard item and cut now.
            $state->commitHardStart($station, $hard);
            $commands->skip($station);

            $this->info(__('Hard start forced: station #:station, item #:item at :time', [
                'station' => $station->id,
                'item' => $hard->id,
                'time' => $hard->fixed_at->format('H:i:s'),
            ]));
        }

        return self::SUCCESS;
    }
}
