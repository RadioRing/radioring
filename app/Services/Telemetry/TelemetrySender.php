<?php

namespace App\Services\Telemetry;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the telemetry report once a day, only while the admin has opted in.
 *
 * Runs hourly from the scheduler and sends when the last report is older than
 * SEND_INTERVAL_MINUTES. Installations therefore report at the hour they opted in,
 * spread over the day rather than all at midnight.
 */
class TelemetrySender
{
    /**
     * A little under a day: the hourly run then sends at the same hour every day,
     * even when one run starts a few seconds later than the one before.
     */
    public const SEND_INTERVAL_MINUTES = 23 * 60 + 30;

    public const TIMEOUT_SECONDS = 10;

    public function isAvailable(): bool
    {
        return $this->endpoint() !== '';
    }

    public function isDue(): bool
    {
        if (! $this->isAvailable() || ! TelemetrySettings::enabled() || TelemetrySettings::instanceId() === null) {
            return false;
        }

        $lastSentAt = TelemetrySettings::lastSentAt();

        return $lastSentAt === null || $lastSentAt->lte(now()->subMinutes(self::SEND_INTERVAL_MINUTES));
    }

    /**
     * Never throws: a failed report is logged and retried on the next run. Playout
     * must never depend on radioring.de being reachable.
     */
    public function send(): bool
    {
        if (! $this->isAvailable() || ! TelemetrySettings::enabled() || TelemetrySettings::instanceId() === null) {
            return false;
        }

        try {
            Http::acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($this->endpoint(), TelemetryReport::build())
                ->throw();
        } catch (Throwable $e) {
            Log::warning('Telemetry report failed: '.$e->getMessage());

            return false;
        }

        TelemetrySettings::markSent();

        return true;
    }

    public function endpoint(): string
    {
        return trim((string) config('radioring.telemetry.endpoint'));
    }
}
