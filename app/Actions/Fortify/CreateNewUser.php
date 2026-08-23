<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AppMode;
use App\Models\InviteCode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'invite_code' => ['required', 'string'],
        ])->validate();

        return DB::transaction(function () use ($input) {
            $invite = InviteCode::whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($input['invite_code']))])
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if (! $invite) {
                throw ValidationException::withMessages([
                    'invite_code' => __('This invite code is invalid or has already been used.'),
                ]);
            }

            // Cloud: every registration opens its own tenant with its own library.
            // Standalone: there is exactly one tenant, and invited users join it.
            $tenant = AppMode::current()->isStandalone()
                ? Tenant::forStandalone()
                : Tenant::create(['name' => $input['name']]);

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            // Assigned explicitly rather than via mass assignment: tenant_id decides which
            // library a user's uploads land in and is never accepted from request input.
            $user->tenant_id = $tenant->id;
            $user->save();

            $invite->update([
                'used_by_user_id' => $user->id,
                'used_at' => now(),
            ]);

            return $user;
        });
    }
}
