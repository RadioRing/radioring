<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Models\Station;
use App\Models\StationStream;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('radioring:prepare-local-stream
    {station? : Slug or ID of the station (default: the first one)}
    {--host=host.docker.internal : Hostname under which the container reaches the app}
    {--port=8000 : Port of the local app}
    {--icecast-password=hackme : Source password of the local Icecast}
    {--playlist= : Slug/ID/name of the playlist for the fallback slot (default: the playlist with the most items)}')]
#[Description('Configures a station for the local Docker Liquidsoap test (output, rundown, .env)')]
class PrepareLocalStream extends Command
{
    public function handle(RundownGeneratorService $generator): int
    {
        $station = $this->resolveStation();

        if (! $station) {
            $this->error(__('No station found. Create a station first.'));

            return self::FAILURE;
        }

        $this->info("Station: #{$station->id} {$station->name} ({$station->slug})");

        $apiBase = 'http://'.$this->option('host').':'.$this->option('port');

        $this->configureOutput($station);
        $this->configureStream($station);
        $rundownCount = $this->ensureRundowns($station, $generator);
        $this->resetLiquidsoapState($station);
        $this->writeAppEnv($apiBase);
        $this->writeDockerEnv($station, $apiBase);

        $this->newLine();
        $this->info(__('Local stream prepared.'));
        $this->line('  '.__('Listen on').' : http://localhost:8010/'.$station->slug.'  (Icecast, e.g. in VLC)');
        $this->line('  Rundowns    : '.__(':count hour(s) generated for today', ['count' => $rundownCount]));
        $this->line('  docker/.env : '.__('written (SLUG, TOKEN, LIQUIDSOAP_API_URL)'));

        if ($rundownCount === 0) {
            $this->newLine();
            $this->warn(__('Careful: the weekly grid holds no playlist for the current hour.'));
            $this->warn(__('Assign one to this hour and run the command again, otherwise /next returns silence.'));
        }

        $this->newLine();
        $this->line(__('Start it now:').'  <comment>./docker/run-local.ps1</comment>');

        return self::SUCCESS;
    }

    private function resolveStation(): ?Station
    {
        $arg = $this->argument('station');

        if ($arg === null) {
            return Station::orderBy('id')->first();
        }

        return Station::where('slug', $arg)
            ->orWhere('id', is_numeric($arg) ? (int) $arg : 0)
            ->first();
    }

    private function configureOutput(Station $station): void
    {
        $station->outputs()->updateOrCreate(
            ['type' => 'icecast', 'mount' => '/'.$station->slug],
            [
                'host' => 'icecast',
                'port' => 8000,
                'password' => $this->option('icecast-password'),
                'bitrate' => 128,
                'enabled' => true,
            ]
        );

        $this->line('  '.__('Icecast output set (host=icecast, mount=/:slug)', ['slug' => $station->slug]));
    }

    private function configureStream(Station $station): void
    {
        StationStream::updateOrCreate(
            ['station_id' => $station->id],
            [
                'container_name' => 'radioring-'.$station->slug,
                'status' => 'stopped',
                'live_port' => 8005,
                'live_password' => Str::random(16),
            ]
        );

        $this->line('  '.__('Stream record set (container=radioring-:slug)', ['slug' => $station->slug]));
    }

    /**
     * Generates rundowns for the current and the next hour.
     *
     * When the current hour has no slot, a fallback slot is created in the
     * weekly grid so that something is audible locally right away.
     */
    private function ensureRundowns(Station $station, RundownGeneratorService $generator): int
    {
        $count = 0;

        foreach ([0, 1] as $offset) {
            $moment = now()->addHours($offset);
            $weekday = $moment->dayOfWeekIso - 1; // 0=Mo ... 6=So
            $date = Carbon::parse($moment->toDateString())->startOfDay();

            $slot = $station->hourGridSlots()
                ->where('weekday', $weekday)
                ->where('hour', $moment->hour)
                ->with('playlist.items.mediaFile')
                ->first();

            // Only create a fallback slot for the current hour
            if (! $slot && $offset === 0) {
                $playlist = $this->pickFallbackPlaylist($station);

                if (! $playlist) {
                    $this->warn('  '.__('No playlist with items exists, cannot create a fallback slot.'));

                    continue;
                }

                $slot = $station->hourGridSlots()->create([
                    'weekday' => $weekday,
                    'hour' => $moment->hour,
                    'playlist_id' => $playlist->id,
                ]);
                $slot->load('playlist.items.mediaFile');

                $this->line('  '.__('Fallback slot created: now, playlist ":name"', ['name' => $playlist->name]));
            }

            if (! $slot) {
                continue;
            }

            try {
                $generator->generate($station, $slot, $date, force: true);
                $count++;
                $this->line('  '.__('Rundown generated for :date :hour:00', ['date' => $date->toDateString(), 'hour' => $moment->hour]));
            } catch (\Throwable $e) {
                $this->warn('  '.__('Rundown for :hour:00 failed: :error', ['hour' => $moment->hour, 'error' => $e->getMessage()]));
            }
        }

        return $count;
    }

    /**
     * Picks the fallback playlist: explicitly via --playlist, or the one with the most items.
     */
    private function pickFallbackPlaylist(Station $station): ?Playlist
    {
        $arg = $this->option('playlist');

        if ($arg !== null) {
            return $station->playlists()
                ->where(function ($query) use ($arg) {
                    $query->where('name', $arg)
                        ->orWhere('id', is_numeric($arg) ? (int) $arg : 0);
                })
                ->first();
        }

        return $station->playlists()
            ->has('items')
            ->withCount('items')
            ->orderByDesc('items_count')
            ->first();
    }

    private function resetLiquidsoapState(Station $station): void
    {
        $station->liquidsoapState()->updateOrCreate(
            ['station_id' => $station->id],
            [
                'current_rundown_id' => null,
                'current_item_position' => 0,
                'now_playing_item_id' => null,
                'now_playing_started_at' => null,
            ]
        );

        $this->line('  '.__('Liquidsoap state reset (position 0)'));
    }

    /**
     * Makes sure the app .env carries LIQUIDSOAP_API_URL so that the generated
     * .liq script embeds the URL that is reachable from inside the container.
     */
    private function writeAppEnv(string $apiBase): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            return;
        }

        $content = file_get_contents($path);
        $line = 'LIQUIDSOAP_API_URL='.$apiBase;

        if (preg_match('/^LIQUIDSOAP_API_URL=.*$/m', $content)) {
            $content = preg_replace('/^LIQUIDSOAP_API_URL=.*$/m', $line, $content);
        } else {
            $content = rtrim($content, "\n")."\n".$line."\n";
        }

        file_put_contents($path, $content);

        // config:clear outside of tests only, otherwise Laravel reconnects to
        // eine frische :memory:-DB und bricht nachfolgende Tests ab.
        if (! app()->runningUnitTests()) {
            $this->call('config:clear');
        }

        $this->line('  '.__('App .env: :line', ['line' => $line]));
    }

    private function writeDockerEnv(Station $station, string $apiBase): void
    {
        $redis = config('database.redis.default', []);
        $redisHost = $redis['host'] ?? '127.0.0.1';

        // Aus Container-Sicht liegt Laravels Redis auf dem Host – localhost muss auf
        // host.docker.internal umgeschrieben werden (compose: extra_hosts host-gateway).
        if (in_array($redisHost, ['127.0.0.1', 'localhost', '::1'], true)) {
            $redisHost = 'host.docker.internal';
        }

        $content = "# Auto-generated by radioring:prepare-local-stream\n"
            ."STATION_SLUG={$station->slug}\n"
            ."STATION_TOKEN={$station->api_token}\n"
            ."LIQUIDSOAP_API_URL={$apiBase}\n"
            ."CONTAINER_NAME=radioring-{$station->slug}\n"
            .'CONTROL_CHANNEL='.config('radioring.control_channel')."\n"
            ."REDIS_HOST={$redisHost}\n"
            .'REDIS_PORT='.($redis['port'] ?? 6379)."\n"
            .'REDIS_PASSWORD='.($redis['password'] ?? '')."\n"
            .'REDIS_DB='.($redis['database'] ?? 0)."\n";

        file_put_contents(base_path('docker/.env'), $content);

        $this->line('  '.__('docker/.env: Redis relay wired up (REDIS_HOST=:host, channel=:channel)', [
            'host' => $redisHost,
            'channel' => config('radioring.control_channel'),
        ]));
    }
}
