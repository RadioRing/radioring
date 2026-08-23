<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Instance-wide settings the operator can change at runtime, without a redeploy.
 *
 * Values live in the database on purpose: the Docker entrypoint runs `config:cache` on
 * every container start, so anything read from the environment is frozen until the next
 * deployment.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * Memoised per request so a value read in routing, a controller and a view costs
     * one query at most.
     *
     * @var array<string, string|null>
     */
    private static array $memo = [];

    private static function cacheKey(string $key): string
    {
        return 'setting:'.$key;
    }

    /**
     * Reads a setting, falling back to the given default.
     *
     * Tolerates a missing table so the application still boots before migrations have
     * run. Artisan itself has to come up in that state.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key] ?? $default;
        }

        try {
            $value = Cache::rememberForever(
                self::cacheKey($key),
                fn () => static::query()->where('key', $key)->value('value'),
            );
        } catch (QueryException) {
            return $default;
        }

        self::$memo[$key] = $value;

        return $value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        self::$memo[$key] = $value;
        Cache::forget(self::cacheKey($key));
    }

    /**
     * Drops the in-process memo. Needed in tests and long-running workers, which would
     * otherwise keep serving a value that was changed elsewhere.
     */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }
}
