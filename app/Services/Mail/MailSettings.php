<?php

namespace App\Services\Mail;

use App\Models\Setting;
use App\Models\Station;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * SMTP settings from the admin area. Override MAIL_* while enabled.
 */
class MailSettings
{
    public const KEY_ENABLED = 'mail_enabled';

    public const KEY_HOST = 'mail_host';

    public const KEY_PORT = 'mail_port';

    public const KEY_ENCRYPTION = 'mail_encryption';

    public const KEY_USERNAME = 'mail_username';

    public const KEY_PASSWORD = 'mail_password';

    public const KEY_FROM_ADDRESS = 'mail_from_address';

    public const KEY_FROM_NAME = 'mail_from_name';

    public const KEY_STATION_SENDER = 'mail_station_sender';

    public const KEY_STATION_SENDER_DOMAIN = 'mail_station_sender_domain';

    /** STARTTLS on a plain connection, usually port 587. */
    public const ENCRYPTION_STARTTLS = 'starttls';

    /** Implicit TLS from the first byte, usually port 465. */
    public const ENCRYPTION_TLS = 'tls';

    /** Short, because alerts are sent inline. */
    public const TIMEOUT_SECONDS = 10;

    /**
     * Do the stored settings replace MAIL_*?
     */
    public static function enabled(): bool
    {
        return Setting::get(self::KEY_ENABLED, '0') === '1';
    }

    /**
     * The stored values without the password.
     *
     * @return array{enabled: bool, host: string, port: int, encryption: string, username: string, from_address: string, from_name: string, station_sender: bool, station_sender_domain: string}
     */
    public static function values(): array
    {
        return [
            'enabled' => self::enabled(),
            'host' => (string) Setting::get(self::KEY_HOST, ''),
            'port' => (int) Setting::get(self::KEY_PORT, '587'),
            'encryption' => (string) Setting::get(self::KEY_ENCRYPTION, self::ENCRYPTION_STARTTLS),
            'username' => (string) Setting::get(self::KEY_USERNAME, ''),
            'from_address' => (string) Setting::get(self::KEY_FROM_ADDRESS, ''),
            'from_name' => (string) Setting::get(self::KEY_FROM_NAME, ''),
            'station_sender' => self::stationSenderEnabled(),
            'station_sender_domain' => (string) Setting::get(self::KEY_STATION_SENDER_DOMAIN, ''),
        ];
    }

    /**
     * A null password keeps the stored one, an empty string removes it.
     *
     * @param  array{enabled: bool, host: string, port: int, encryption: string, username: string, from_address: string, from_name: string, station_sender: bool, station_sender_domain: string}  $values
     */
    public static function store(array $values, ?string $password = null): void
    {
        Setting::set(self::KEY_ENABLED, $values['enabled'] ? '1' : '0');
        Setting::set(self::KEY_HOST, $values['host']);
        Setting::set(self::KEY_PORT, (string) $values['port']);
        Setting::set(self::KEY_ENCRYPTION, $values['encryption']);
        Setting::set(self::KEY_USERNAME, $values['username']);
        Setting::set(self::KEY_FROM_ADDRESS, $values['from_address']);
        Setting::set(self::KEY_FROM_NAME, $values['from_name']);
        Setting::set(self::KEY_STATION_SENDER, $values['station_sender'] ? '1' : '0');
        Setting::set(self::KEY_STATION_SENDER_DOMAIN, Str::lower(trim($values['station_sender_domain'])));

        if ($password !== null) {
            Setting::set(self::KEY_PASSWORD, $password === '' ? null : Crypt::encryptString($password));
        }
    }

    /**
     * Encrypted with APP_KEY, as backups contain the database.
     */
    public static function password(): ?string
    {
        $stored = Setting::get(self::KEY_PASSWORD);

        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // APP_KEY changed. The test mail reports the failed login.
            return null;
        }
    }

    public static function hasPassword(): bool
    {
        return self::password() !== null;
    }

    /**
     * Points the mailer at the given or stored server. No-op while disabled.
     *
     * @param  array{enabled: bool, host: string, port: int, encryption: string, username: string, from_address: string, from_name: string}|null  $values  Unsaved values to try out, or null for the stored ones
     */
    public static function apply(?array $values = null, ?string $password = null): void
    {
        if ($values === null) {
            if (! self::enabled()) {
                return;
            }

            $values = self::values();
            $password = self::password();
        }

        if (! $values['enabled']) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.scheme' => $values['encryption'] === self::ENCRYPTION_TLS ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => $values['host'],
            'mail.mailers.smtp.port' => $values['port'],
            'mail.mailers.smtp.username' => $values['username'] !== '' ? $values['username'] : null,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.timeout' => self::TIMEOUT_SECONDS,
        ]);

        if ($values['from_address'] !== '') {
            config(['mail.from.address' => $values['from_address']]);
        }

        if ($values['from_name'] !== '') {
            config(['mail.from.name' => $values['from_name']]);
        }

        // Drop a mailer resolved with the old config.
        Mail::purge('smtp');
    }

    public static function stationSenderEnabled(): bool
    {
        return Setting::get(self::KEY_STATION_SENDER, '0') === '1';
    }

    /**
     * The configured domain, or the domain of the general sender address.
     */
    public static function stationSenderDomain(): ?string
    {
        $domain = trim((string) Setting::get(self::KEY_STATION_SENDER_DOMAIN, ''));

        $fromAddress = (string) config('mail.from.address');

        if ($domain === '' && str_contains($fromAddress, '@')) {
            $domain = Str::afterLast($fromAddress, '@');
        }

        return $domain !== '' ? Str::lower($domain) : null;
    }

    /**
     * `<slug>-noreply@<domain>` with the station name, or null for the general sender.
     */
    public static function stationSender(Station $station): ?Address
    {
        if (! self::stationSenderEnabled()) {
            return null;
        }

        $domain = self::stationSenderDomain();

        if ($domain === null) {
            return null;
        }

        return new Address($station->slug.'-noreply@'.$domain, $station->name);
    }
}
