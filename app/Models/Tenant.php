<?php

namespace App\Models;

use App\Enums\AppMode;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant owns stations and the media library they share.
 *
 * Access to the library is never derived from a tenant directly, but always through a
 * station the user is a member of: user → station_users → station → tenant → media_files.
 * See docs/opensource-umbau.md, section 7.3.
 */
#[Fillable(['name', 'station_quota'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    /**
     * Users whose *home* tenant this is: where their new stations and uploads go.
     * This is not the list of users who can access the tenant; see the class docblock.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * The one tenant of a standalone installation, created on first use.
     *
     * Standalone means a single tenant by definition, so everyone who registers joins
     * this one instead of opening their own.
     */
    public static function forStandalone(): self
    {
        return static::query()->oldest('id')->first()
            ?? static::create(['name' => (string) config('app.name', 'RadioRing')]);
    }

    /**
     * Station quotas are a cloud billing concept; a standalone operator is not limited.
     */
    public function canCreateStation(): bool
    {
        if (AppMode::current()->isStandalone()) {
            return true;
        }

        return $this->stations()->count() < $this->station_quota;
    }
}
