<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Throwable;

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
     * Never throws. Settings are read while the console kernel boots, so an unreachable
     * cache store or a database that has not been migrated yet would otherwise take down
     * every Artisan command, `key:generate` on a fresh installation included. An
     * unreachable cache falls back to a direct query, an unreachable database to the
     * default.
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
        } catch (Throwable) {
            // The cache store itself is unavailable (Redis down, misconfigured). The
            // database is the authority anyway, the cache only saves it a query.
            try {
                $value = static::query()->where('key', $key)->value('value');
            } catch (QueryException) {
                return $default;
            }
        }

        self::$memo[$key] = $value;

        return $value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        self::$memo[$key] = $value;

        try {
            Cache::forget(self::cacheKey($key));
        } catch (Throwable) {
            // An unreachable cache must not undo a write that already landed in the
            // database. Nothing was cached in that state either.
        }
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
