<?php

namespace App\Enums;

use App\Models\Setting;

/**
 * How this installation is operated.
 *
 * Standalone is a single-tenant install: one operator, one media library, editors invited
 * into it. Cloud serves many independent tenants that must never see each other.
 *
 * Deliberately narrow: the mode decides which controls are shown, plus exactly three
 * behavioural rules: tenant assignment on registration, the station quota, and account
 * banning. It must not spread further into services, jobs or models; the data model is
 * identical in both modes, standalone simply holds a single tenant.
 *
 * The value is stored in the database (see Setting) so an operator can switch it from the
 * admin area without redeploying. RADIORING_MODE only supplies the initial default, since
 * the Docker entrypoint caches the config on every start.
 *
 * Because the mode can change while the application is running, routes must never be
 * registered conditionally on it, because route definitions are cached at boot. Guard the
 * behaviour instead (see ImpersonationController).
 */
enum AppMode: string
{
    case Standalone = 'standalone';
    case Cloud = 'cloud';

    public const SETTING_KEY = 'app_mode';

    public static function current(): self
    {
        $stored = Setting::get(self::SETTING_KEY);

        return self::tryFrom((string) $stored)
            ?? self::tryFrom((string) config('radioring.mode'))
            ?? self::Standalone;
    }

    /**
     * Persists the mode for the whole instance, effective immediately.
     */
    public static function switchTo(self $mode): void
    {
        Setting::set(self::SETTING_KEY, $mode->value);
    }

    public function isStandalone(): bool
    {
        return $this === self::Standalone;
    }

    public function isCloud(): bool
    {
        return $this === self::Cloud;
    }

    /**
     * Does this mode serve more than one tenant? Everything multi-tenant (impersonation,
     * station quotas, banning accounts) hangs off this.
     */
    public static function isMultiTenant(): bool
    {
        return self::current()->isCloud();
    }

    public function label(): string
    {
        return match ($this) {
            self::Standalone => __('Standalone (one tenant)'),
            self::Cloud => __('Cloud (multiple tenants)'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Standalone => __('One installation, one shared media library. Invited users join it. No station quota, no impersonation, no account bans.'),
            self::Cloud => __('Many independent tenants. Every registration opens its own tenant with its own media library.'),
        };
    }
}
