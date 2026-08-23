<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
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
     * The role this user holds on the given station: 'owner' or 'editor', null if none.
     */
    public function roleOn(Station $station): ?string
    {
        $pivot = $this->accessibleStations()->find($station->id)?->pivot;

        return $pivot?->role;
    }

    /**
     * May this user modify the tenant's media library through the given station?
     *
     * Owners may do everything. Editors may add and tag material so they can build
     * their own shows, but may not delete: the library is shared across every station
     * of the tenant, so a delete would reach far beyond the station they were invited to.
     */
    public function mayWriteMediaOn(Station $station): bool
    {
        return in_array($this->roleOn($station), ['owner', 'editor'], true);
    }

    public function mayDeleteMediaOn(Station $station): bool
    {
        return $this->roleOn($station) === 'owner';
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
