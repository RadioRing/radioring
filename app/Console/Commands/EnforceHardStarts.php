<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\LiquidsoapCommandService;
use App\Services\LiquidsoapStateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radioring:enforce-hard-starts')]
#[Description('Erzwingt zur vollen Stunde den Wechsel auf Hard-Start-Rundowns (sample-genau via skip)')]
class EnforceHardStarts extends Command
{
    public function handle(LiquidsoapStateService $state, LiquidsoapCommandService $commands): int
    {
        // Nur Stationen mit laufendem Container betrachten.
        $stations = Station::whereHas('stream', fn ($q) => $q->where('status', 'running'))->get();

        foreach ($stations as $station) {
            $hard = $state->pendingHardStart($station);

            if (! $hard) {
                continue;
            }

            // Cursor auf den Hard-Rundown setzen, dann sofort skippen → Liquidsoap
            // zieht /next und bekommt Track 0 des Hard-Rundowns.
            $state->commitHardStart($station, $hard);
            $commands->skip($station);

            $this->info("Hard-Start erzwungen: Station #{$station->id} → Rundown #{$hard->id} ".sprintf('%02d:00', $hard->broadcast_hour));
        }

        return self::SUCCESS;
    }
}
