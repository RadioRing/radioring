<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Instance-wide acceptance of the Thimeo Stereo Tool licence.
 *
 * Thimeo permits RadioRing to ship the Stereo Tool shared library and its presets inside
 * the station image on the condition that the licence they fall under is stated clearly.
 * The static notices in NOTICE.md, README.md and /opt/stereotool/LICENSE-Thimeo.md cover
 * everyone who pulls the image; this covers the operator who actually switches the
 * processing on, and records who accepted and when.
 *
 * Acceptance is a precondition for enabling Stereo Tool on any station, never a
 * consequence of it. Withdrawing it stops the processing everywhere (see revoke).
 */
class StereoToolTerms
{
    public const ACCEPTED_AT = 'stereo_tool.terms_accepted_at';

    public const ACCEPTED_BY = 'stereo_tool.terms_accepted_by';

    public static function accepted(): bool
    {
        return filled(Setting::get(self::ACCEPTED_AT));
    }

    public static function acceptedAt(): ?Carbon
    {
        $stored = Setting::get(self::ACCEPTED_AT);

        return filled($stored) ? Carbon::parse($stored) : null;
    }

    public static function acceptedBy(): ?User
    {
        $id = Setting::get(self::ACCEPTED_BY);

        return filled($id) ? User::find((int) $id) : null;
    }

    public static function accept(User $user): void
    {
        Setting::set(self::ACCEPTED_AT, now()->toIso8601String());
        Setting::set(self::ACCEPTED_BY, (string) $user->id);
    }

    public static function revoke(): void
    {
        Station::query()->where('stereo_tool_enabled', true)->update(['stereo_tool_enabled' => false]);

        Setting::set(self::ACCEPTED_AT, null);
        Setting::set(self::ACCEPTED_BY, null);
    }

    public static function licenceUrl(): string
    {
        return (string) config('radioring.stereo_tool.licence_url');
    }
}
