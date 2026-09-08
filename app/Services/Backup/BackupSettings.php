<?php

namespace App\Services\Backup;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The operator's backup configuration.
 *
 * Lives in the settings table, not the environment: the Docker entrypoint caches the
 * config on every start, so an env value could not be changed without redeploying.
 */
class BackupSettings
{
    public const KEY_AUTO_ENABLED = 'backup_auto_enabled';

    public const KEY_AUTO_TIME = 'backup_auto_time';

    public const KEY_RETENTION = 'backup_retention';

    public const KEY_PASSPHRASE = 'backup_passphrase';

    public const DEFAULT_TIME = '03:00';

    public const DEFAULT_RETENTION = 7;

    public static function autoEnabled(): bool
    {
        return Setting::get(self::KEY_AUTO_ENABLED, '0') === '1';
    }

    public static function setAutoEnabled(bool $enabled): void
    {
        Setting::set(self::KEY_AUTO_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * Time of day for the nightly run, as `H:i`.
     */
    public static function autoTime(): string
    {
        $value = (string) Setting::get(self::KEY_AUTO_TIME, self::DEFAULT_TIME);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : self::DEFAULT_TIME;
    }

    public static function setAutoTime(string $time): void
    {
        Setting::set(self::KEY_AUTO_TIME, $time);
    }

    /**
     * How many finished backups are kept. Older ones are deleted after a successful run.
     */
    public static function retention(): int
    {
        return max(1, (int) Setting::get(self::KEY_RETENTION, (string) self::DEFAULT_RETENTION));
    }

    public static function setRetention(int $count): void
    {
        Setting::set(self::KEY_RETENTION, (string) max(1, $count));
    }

    /**
     * Passphrase used for automatic backups, or null when they stay unencrypted.
     *
     * Stored encrypted with APP_KEY. That protects it against a leaked database dump, not
     * against somebody who already holds the whole installation. The point is the archive
     * that leaves the server, and that one is properly protected.
     */
    public static function passphrase(): ?string
    {
        $stored = Setting::get(self::KEY_PASSPHRASE);

        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // APP_KEY changed. Treat it as "no passphrase" rather than failing every
            // nightly run from here on; the UI shows that none is set.
            return null;
        }
    }

    public static function setPassphrase(?string $passphrase): void
    {
        Setting::set(
            self::KEY_PASSPHRASE,
            $passphrase === null || $passphrase === '' ? null : Crypt::encryptString($passphrase),
        );
    }

    public static function hasPassphrase(): bool
    {
        return self::passphrase() !== null;
    }
}
