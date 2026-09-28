<?php

namespace App\Services;

use App\Enums\StationAlertType;
use App\Mail\StationAlertMail;
use App\Models\GeneratedPlaylist;
use App\Models\Station;
use App\Models\StationAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mails station owners when a station goes off air, and again when it is back.
 *
 * State-based: each run compares current conditions with the open alerts, so every
 * episode gets one alert and one all-clear. Sends inline, not via the queue, so a stuck
 * queue cannot swallow alerts.
 */
class StationAlertMonitor
{
    /** Conditions in which listeners do not hear the programme. */
    private const OFF_AIR_TYPES = [
        StationAlertType::Silence,
        StationAlertType::EmergencyLoop,
        StationAlertType::NoPlayout,
    ];

    public function check(): void
    {
        $stations = Station::query()
            ->where('status', 'active')
            ->orWhereHas('alerts', fn ($query) => $query->open())
            ->with(['liquidsoapState', 'stream', 'alerts' => fn ($query) => $query->open()])
            ->get();

        foreach ($stations as $station) {
            try {
                $this->checkStation($station);
            } catch (Throwable $e) {
                Log::error("Station alert check failed for station #{$station->id}: {$e->getMessage()}");
            }
        }
    }

    public function checkStation(Station $station): void
    {
        $watched = $this->isWatched($station);
        $active = $watched ? $this->activeConditions($station) : [];

        // Switching between off-air conditions is no all-clear.
        $stillOffAir = array_filter($active, fn (StationAlertType $type) => in_array($type, self::OFF_AIR_TYPES, true)) !== [];

        foreach ($station->alerts as $alert) {
            if (in_array($alert->type, $active, true)) {
                continue;
            }

            // Unwatched means stopped on purpose: close silently.
            $notify = $watched && ! ($stillOffAir && in_array($alert->type, self::OFF_AIR_TYPES, true));

            $this->resolve($station, $alert, $notify);
        }

        foreach ($active as $type) {
            $alert = $station->alerts->first(fn (StationAlert $open) => $open->type === $type)
                ?? $station->alerts()->create(['type' => $type, 'started_at' => now()]);

            if ($alert->notified_at === null && $alert->started_at->diffInSeconds(now()) >= $this->delaySeconds()) {
                $this->notify($station, $alert, resolved: false);
            }
        }
    }

    /**
     * Is the station meant to be on air?
     */
    public function isWatched(Station $station): bool
    {
        return $station->status === 'active'
            && $station->alert_emails_enabled
            && in_array($station->stream?->status, ['running', 'error'], true);
    }

    /**
     * What is wrong with the station right now.
     *
     * @return list<StationAlertType>
     */
    public function activeConditions(Station $station): array
    {
        $conditions = [];
        $state = $station->liquidsoapState;

        if ($state?->onEmergency()) {
            $conditions[] = StationAlertType::EmergencyLoop;
        } elseif ($state?->isUnderrun()) {
            $conditions[] = StationAlertType::Silence;
        } elseif ($this->hasNoPlayout($station)) {
            $conditions[] = StationAlertType::NoPlayout;
        }

        if ($this->rundownIsMissing($station)) {
            $conditions[] = StationAlertType::RundownMissing;
        }

        return $conditions;
    }

    /**
     * A failed start, or no track reported once the start delay has passed.
     */
    private function hasNoPlayout(Station $station): bool
    {
        $stream = $station->stream;

        if ($stream?->status === 'error') {
            return true;
        }

        $startedAt = $stream?->last_started_at;

        if ($startedAt !== null && $startedAt->diffInSeconds(now()) < $this->delaySeconds()) {
            return false;
        }

        $state = $station->liquidsoapState;

        if ($state === null) {
            return true;
        }

        return ! $state->live_active && $state->nowPlayingHasEnded();
    }

    /**
     * The grid has a slot for the current hour but no playable rundown exists.
     */
    private function rundownIsMissing(Station $station): bool
    {
        $now = now();

        $hasSlot = $station->hourGridSlots()
            ->where('weekday', $now->dayOfWeekIso - 1)
            ->where('hour', $now->hour)
            ->exists();

        if (! $hasSlot) {
            return false;
        }

        return ! GeneratedPlaylist::where('station_id', $station->id)
            ->whereDate('broadcast_date', $now->toDateString())
            ->where('broadcast_hour', $now->hour)
            ->whereIn('status', ['ready', 'played'])
            ->exists();
    }

    private function resolve(Station $station, StationAlert $alert, bool $notify): void
    {
        // Never mailed, so no all-clear needed.
        if ($alert->notified_at === null) {
            $alert->delete();

            return;
        }

        $alert->update(['resolved_at' => now()]);

        if ($notify) {
            $this->notify($station, $alert, resolved: true);
        }
    }

    /**
     * One mail per recipient. Marked as sent once at least one succeeded, otherwise the
     * next run retries.
     */
    private function notify(Station $station, StationAlert $alert, bool $resolved): void
    {
        $recipients = $station->alertRecipients();
        $sent = $recipients->isEmpty();

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(new StationAlertMail($station, $alert, $resolved));
                $sent = true;
            } catch (Throwable $e) {
                Log::error("Station alert mail to user #{$recipient->id} failed for station #{$station->id}: {$e->getMessage()}");
            }
        }

        if ($sent) {
            $alert->update([$resolved ? 'resolved_notified_at' : 'notified_at' => now()]);
        }
    }

    private function delaySeconds(): int
    {
        return (int) config('radioring.alerts.delay_seconds', 120);
    }
}
