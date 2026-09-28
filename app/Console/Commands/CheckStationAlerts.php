<?php

namespace App\Console\Commands;

use App\Services\StationAlertMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radioring:check-alerts')]
#[Description('Checks every station for being off air and mails its owners.')]
class CheckStationAlerts extends Command
{
    public function handle(StationAlertMonitor $monitor): int
    {
        $monitor->check();

        return self::SUCCESS;
    }
}
