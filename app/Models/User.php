<?php

namespace App\Models;

use App\Enums\StationRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'receives_alert_emails'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Mirrors the column default for models not yet reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'receives_alert_emails' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'receives_alert_emails' => 'boolean',
            'banned_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->is_admin === true;
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /**
     * The user's home tenant: where their new stations and uploads go by default.
     *
     * NEVER use this to decide what a user may see. Access is always derived through
     * a station the user is a member of. See accessibleStations() and
     * docs/opensource-umbau.md, section 7.3.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Stationen, die diesem Nutzer gehören (für Quota/Verwaltung).
     */
    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    /**
     * Alle Stationen, auf die der Nutzer Zugriff hat (eigene + geteilte).
     */
    public function accessibleStations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class, 'station_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentStation(): ?Station
    {
        $stationId = session('current_station_id');

        if (! $stationId) {
            return null;
        }

        return $this->accessibleStations()->find($stationId);
    }

    public function setCurrentStation(Station $station): void
    {
        session(['current_station_id' => $station->id]);
    }

    public function canCreateStation(): bool
    {
        return $this->tenant?->canCreateStation() ?? false;
    }

    /**
     * Role on the given station, null if not a member. Not memoised: a removal must
     * take effect on the next request, even in a component that stays open.
     */
    public function roleOn(Station $station): ?StationRole
    {
        $role = DB::table('station_users')
            ->where('station_id', $station->id)
            ->where('user_id', $this->id)
            ->value('role');

        return $role !== null ? StationRole::tryFrom($role) : null;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
