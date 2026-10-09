<?php

namespace App\Console\Commands;

use App\Services\Telemetry\TelemetrySender;
use App\Services\Telemetry\TelemetrySettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radioring:send-telemetry {--force : Send now, even if today\'s report has already gone out}')]
#[Description('Sends the anonymous usage report to radioring.de, if the admin has opted in.')]
class SendTelemetry extends Command
{
    public function handle(TelemetrySender $sender): int
    {
        if (! TelemetrySettings::enabled()) {
            $this->line(__('Telemetry is switched off.'));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $sender->isDue()) {
            $this->line(__('Telemetry has already been sent today.'));

            return self::SUCCESS;
        }

        if (! $sender->send()) {
            $this->warn(__('Sending the telemetry report failed. It will be retried within the hour.'));

            return self::SUCCESS;
        }

        $this->info(__('Telemetry report sent.'));

        return self::SUCCESS;
    }
}
