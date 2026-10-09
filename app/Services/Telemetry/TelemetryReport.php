<?php

namespace App\Services\Telemetry;

use App\Enums\AppMode;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Station;
use App\Models\StationLog;
use App\Models\StationOutput;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\TelemetryBucket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the anonymous telemetry payload (schema 1).
 *
 * Nothing that names or locates anything: no station names, slugs, URLs, hosts,
 * credentials or content. Counts are sent as TelemetryBucket ranges, everything else
 * as yes/no. The admin can see exactly this array before opting in.
 */
class TelemetryReport
{
    public const SCHEMA_VERSION = 1;

    /** Live input counts as used when it was on air within this window. */
    public const LIVE_INPUT_DAYS = 30;

    /**
     * @return array{schema: int, instance_id: ?string, version: string, channel: string, mode: string, php: string, database: string, tenants: string, users: string, stations: list<array<string, string|bool>>}
     */
    public static function build(?string $instanceId = null): array
    {
        $version = AppVersion::fromConfig();

        return [
            'schema' => self::SCHEMA_VERSION,
            'instance_id' => $instanceId ?? TelemetrySettings::instanceId(),
            'version' => substr((string) preg_replace('/[^0-9A-Za-z.+\-]/', '', $version->name()), 0, 32) ?: 'unknown',
            'channel' => match (true) {
                $version->isRelease() => 'release',
                $version->isDevelopment() => 'development',
                default => 'edge',
            },
            'mode' => AppMode::current()->value,
            'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'database' => self::databaseDriver(),
            'tenants' => TelemetryBucket::for(Tenant::count()),
            'users' => TelemetryBucket::for(User::count()),
            'stations' => self::stations(),
        ];
    }

    private static function databaseDriver(): string
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'], true) ? $driver : 'other';
    }

    /**
     * One entry per station, in no particular order and without any identifier, so
     * entries cannot be followed from one report to the next.
     *
     * @return list<array<string, string|bool>>
     */
    private static function stations(): array
    {
        $mediaPerTenant = MediaFile::query()
            ->toBase()
            ->selectRaw('tenant_id, count(*) as aggregate')
            ->groupBy('tenant_id')
            ->pluck('aggregate', 'tenant_id');

        $tenantsWithAirtimeRules = MediaFile::query()
            ->where(fn (Builder $query) => $query
                ->whereNotNull('airtime_windows')
                ->orWhereNotNull('airable_from')
                ->orWhereNotNull('airable_until'))
            ->distinct()
            ->pluck('tenant_id')
            ->flip();

        $stationsWithMarkers = self::stationsWithItems(fn (Builder $query) => $query->where('playlist_items.type', 'marker'));
        $stationsWithHardTimes = self::stationsWithItems(fn (Builder $query) => $query->where('playlist_items.fixed_mode', 'hard'));

        $stationsWithLiveInput = StationLog::query()
            ->where('event', StationLog::EVENT_LIVE_STARTED)
            ->where('occurred_at', '>=', now()->subDays(self::LIVE_INPUT_DAYS))
            ->distinct()
            ->pluck('station_id')
            ->flip();

        return Station::query()
            ->withCount([
                'playlists as playlist_count' => fn (Builder $query) => $query->where('kind', Playlist::KIND_PLAYLIST),
                'playlists as container_count' => fn (Builder $query) => $query->where('kind', Playlist::KIND_CONTAINER),
                'hourGridSlots',
                'externalSources as syndication_source_count' => fn (Builder $query) => $query->where('kind', 'syndication'),
                'externalSources as news_weather_count' => fn (Builder $query) => $query->whereIn('kind', ['news', 'weather', 'news_weather']),
                'externalSources as url_source_count' => fn (Builder $query) => $query->where('kind', 'url'),
                'emergencyItems',
            ])
            ->with(['outputs' => fn ($query) => $query->where('enabled', true)])
            ->inRandomOrder()
            ->get()
            ->map(fn (Station $station): array => [
                'media_files' => TelemetryBucket::for((int) ($mediaPerTenant[$station->tenant_id] ?? 0)),
                'playlists' => TelemetryBucket::for($station->playlist_count),
                'containers' => TelemetryBucket::for($station->container_count),
                'hour_grid_slots' => TelemetryBucket::for($station->hour_grid_slots_count),
                'syndication_sources' => TelemetryBucket::for($station->syndication_source_count),
                'output_internal' => self::hasOutput($station->outputs, 'internal'),
                'output_icecast' => self::hasOutput($station->outputs, 'icecast'),
                'output_lautfm' => self::hasOutput($station->outputs, 'lautfm'),
                'syndication' => $station->hasSyndicationConnection() || $station->syndication_source_count > 0,
                'news_weather' => $station->news_weather_count > 0,
                'url_sources' => $station->url_source_count > 0,
                'markers' => $stationsWithMarkers->has($station->id),
                'hard_fixed_times' => $stationsWithHardTimes->has($station->id),
                'airtime_windows' => $tenantsWithAirtimeRules->has($station->tenant_id),
                'live_input' => $stationsWithLiveInput->has($station->id),
                'stereo_tool' => $station->stereoToolActive(),
                'emergency_loop' => $station->emergency_items_count > 0,
            ])
            ->values()
            ->all();
    }

    /**
     * IDs of stations with at least one playlist item matching the constraint, as keys.
     *
     * @param  callable(Builder<PlaylistItem>): mixed  $constraint
     * @return Collection<int, int>
     */
    private static function stationsWithItems(callable $constraint): Collection
    {
        return PlaylistItem::query()
            ->join('playlists', 'playlists.id', '=', 'playlist_items.playlist_id')
            ->tap($constraint)
            ->distinct()
            ->pluck('playlists.station_id')
            ->flip();
    }

    /**
     * @param  Collection<int, StationOutput>  $outputs
     */
    private static function hasOutput(Collection $outputs, string $type): bool
    {
        return $outputs->contains(fn (StationOutput $output): bool => $output->type === $type);
    }
}
