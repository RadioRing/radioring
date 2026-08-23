<?php

namespace App\Console\Commands;

use App\Enums\AppMode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('user:manage
    {email : Email address of the user}
    {--create : Create the user when it does not exist yet}
    {--name= : Name of the new user (only with --create)}
    {--verify : Mark the email as verified}
    {--unverify : Withdraw the email verification}
    {--quota= : Set the station quota (how many stations are allowed)}
    {--password= : Set a new password}
    {--admin : Grant admin rights}
    {--no-admin : Revoke admin rights}
    {--ban : Ban the account}
    {--unban : Lift the ban}')]
#[Description('Edits a user by email (verify, quota, password)')]
class ManageUser extends Command
{
    private bool $wasJustCreated = false;

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user && $this->option('create')) {
            $user = $this->createUser($email);

            if (! $user) {
                return self::FAILURE;
            }
        }

        if (! $user) {
            $this->error(__('No user with the email :email found.', ['email' => $email]));

            return self::FAILURE;
        }

        $changed = false;

        if ($this->option('verify')) {
            $user->email_verified_at = now();
            $changed = true;
            $this->line('  '.__('Email marked as verified.'));
        }

        if ($this->option('unverify')) {
            $user->email_verified_at = null;
            $changed = true;
            $this->line('  '.__('Email verification withdrawn.'));
        }

        if ($this->option('quota') !== null) {
            // The quota lives on the tenant now, not the user.
            $user->tenant?->update(['station_quota' => (int) $this->option('quota')]);
            $changed = true;
            $this->line('  '.__('Station quota: :quota', ['quota' => $user->tenant?->station_quota]));
        }

        if ($this->option('password') !== null) {
            $user->password = Hash::make((string) $this->option('password'));
            $changed = true;
            $this->line('  '.__('Password set.'));
        }

        if ($this->option('admin')) {
            $user->is_admin = true;
            $changed = true;
            $this->line('  '.__('Admin rights granted.'));
        }

        if ($this->option('no-admin')) {
            $user->is_admin = false;
            $changed = true;
            $this->line('  '.__('Admin rights revoked.'));
        }

        if ($this->option('ban')) {
            $user->banned_at = now();
            $changed = true;
            $this->line('  '.__('Account banned.'));
        }

        if ($this->option('unban')) {
            $user->banned_at = null;
            $changed = true;
            $this->line('  '.__('Ban lifted.'));
        }

        if (! $changed && $this->wasJustCreated) {
            $this->info(__('User created.'));
        } elseif (! $changed) {
            $this->warn(__('No change requested. Available options: --verify, --unverify, --quota=, --password='));
        } else {
            $user->save();
            $this->info(__('User saved.'));
        }

        $this->newLine();
        $no = __('no');

        $this->table([__('Field'), __('Value')], [
            [__('ID'), $user->id],
            [__('Name'), $user->name],
            [__('Email'), $user->email],
            [__('Verified'), $user->email_verified_at ? $user->email_verified_at->toDateTimeString() : $no],
            [__('Admin'), $user->is_admin ? __('yes') : $no],
            [__('Banned'), $user->banned_at ? $user->banned_at->toDateTimeString() : $no],
            [__('Station quota'), $user->tenant?->station_quota ?? '-'],
            [__('Stations'), $user->stations()->count()],
        ]);

        return self::SUCCESS;
    }

    /**
     * Create a user without an invite code.
     *
     * Registration consumes an invite; the CLI is already privileged, so it does
     * not. Tenant assignment mirrors CreateNewUser: standalone joins the single
     * tenant, cloud opens a new one. tenant_id is set explicitly rather than
     * mass assigned, because it decides which library the uploads land in.
     */
    private function createUser(string $email): ?User
    {
        $name = (string) ($this->option('name') ?: Str::before($email, '@'));
        $password = (string) ($this->option('password') ?: Str::password(20));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        $user = DB::transaction(function () use ($name, $email, $password): User {
            $tenant = AppMode::current()->isStandalone()
                ? Tenant::forStandalone()
                : Tenant::create(['name' => $name]);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->tenant_id = $tenant->id;
            $user->save();

            return $user;
        });

        $this->wasJustCreated = true;
        $this->line('  '.__('User :email created.', ['email' => $email]));

        if (! $this->option('password')) {
            $this->newLine();
            $this->warn('  '.__('Generated password: :password', ['password' => $password]));
            $this->warn('  '.__('It is shown this one time only. Please change it after signing in.'));
            $this->newLine();
        }

        return $user;
    }
}
