<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('user:manage
    {email : E-Mail-Adresse des Users}
    {--verify : E-Mail als verifiziert markieren (freischalten)}
    {--unverify : E-Mail-Verifizierung zurücknehmen}
    {--quota= : Station-Quota setzen (Anzahl erlaubter Stationen)}
    {--password= : Neues Passwort setzen}
    {--admin : Admin-Rechte erteilen}
    {--no-admin : Admin-Rechte entziehen}
    {--ban : Konto sperren}
    {--unban : Sperre aufheben}')]
#[Description('Manipuliert einen User anhand seiner E-Mail (freischalten, Quota, Passwort)')]
class ManageUser extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("Kein User mit E-Mail {$this->argument('email')} gefunden.");

            return self::FAILURE;
        }

        $changed = false;

        if ($this->option('verify')) {
            $user->email_verified_at = now();
            $changed = true;
            $this->line('  → E-Mail freigeschaltet (verifiziert).');
        }

        if ($this->option('unverify')) {
            $user->email_verified_at = null;
            $changed = true;
            $this->line('  → E-Mail-Verifizierung zurückgenommen.');
        }

        if ($this->option('quota') !== null) {
            // The quota lives on the tenant now, not the user.
            $user->tenant?->update(['station_quota' => (int) $this->option('quota')]);
            $changed = true;
            $this->line('  → Station-Quota: '.$user->tenant?->station_quota);
        }

        if ($this->option('password') !== null) {
            $user->password = Hash::make((string) $this->option('password'));
            $changed = true;
            $this->line('  → Passwort gesetzt.');
        }

        if ($this->option('admin')) {
            $user->is_admin = true;
            $changed = true;
            $this->line('  → Admin-Rechte erteilt.');
        }

        if ($this->option('no-admin')) {
            $user->is_admin = false;
            $changed = true;
            $this->line('  → Admin-Rechte entzogen.');
        }

        if ($this->option('ban')) {
            $user->banned_at = now();
            $changed = true;
            $this->line('  → Konto gesperrt.');
        }

        if ($this->option('unban')) {
            $user->banned_at = null;
            $changed = true;
            $this->line('  → Sperre aufgehoben.');
        }

        if (! $changed) {
            $this->warn('Keine Änderung angegeben. Verfügbare Optionen: --verify, --unverify, --quota=, --password=');
        } else {
            $user->save();
            $this->info('User gespeichert.');
        }

        $this->newLine();
        $this->table(['Feld', 'Wert'], [
            ['ID', $user->id],
            ['Name', $user->name],
            ['E-Mail', $user->email],
            ['Verifiziert', $user->email_verified_at ? $user->email_verified_at->toDateTimeString() : 'nein'],
            ['Admin', $user->is_admin ? 'ja' : 'nein'],
            ['Gesperrt', $user->banned_at ? $user->banned_at->toDateTimeString() : 'nein'],
            ['Station-Quota', $user->tenant?->station_quota ?? '–'],
            ['Stationen', $user->stations()->count()],
        ]);

        return self::SUCCESS;
    }
}
