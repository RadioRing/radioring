<?php

namespace App\Services\Telemetry;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Opt-in state of the anonymous telemetry. Off until an admin switches it on.
 */
class TelemetrySettings
{
    public const KEY_ENABLED = 'telemetry_enabled';

    public const KEY_INSTANCE_ID = 'telemetry_instance_id';

    public const KEY_LAST_SENT_AT = 'telemetry_last_sent_at';

    public static function enabled(): bool
    {
        return Setting::get(self::KEY_ENABLED, '0') === '1';
    }

    /**
     * The ID is random and created on opt-in, so it cannot be derived from anything
     * else about the installation. Opting out drops it: a later opt-in starts afresh.
     */
    public static function enable(): void
    {
        if (self::instanceId() === null) {
            self::resetInstanceId();
        }

        Setting::set(self::KEY_ENABLED, '1');
    }

    public static function disable(): void
    {
        Setting::set(self::KEY_ENABLED, '0');
        Setting::set(self::KEY_INSTANCE_ID, null);
        Setting::set(self::KEY_LAST_SENT_AT, null);
    }

    public static function instanceId(): ?string
    {
        $id = Setting::get(self::KEY_INSTANCE_ID);

        return filled($id) ? $id : null;
    }

    /**
     * The receiving side then sees a new installation, the old entry expires there.
     */
    public static function resetInstanceId(): string
    {
        $id = (string) Str::uuid();

        Setting::set(self::KEY_INSTANCE_ID, $id);
        Setting::set(self::KEY_LAST_SENT_AT, null);

        return $id;
    }

    public static function lastSentAt(): ?CarbonImmutable
    {
        $value = Setting::get(self::KEY_LAST_SENT_AT);

        return filled($value) ? CarbonImmutable::parse($value) : null;
    }

    public static function markSent(): void
    {
        Setting::set(self::KEY_LAST_SENT_AT, now()->toIso8601String());
    }
}
