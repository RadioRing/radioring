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
    {station? : Slug oder ID der Station (Standard: erste Station)}
    {--host=host.docker.internal : Hostname, unter dem der Container die App erreicht}
    {--port=8000 : Port der lokalen App}
    {--icecast-password=hackme : Source-Passwort des lokalen Icecast}
    {--playlist= : Slug/ID/Name der Playlist für den Fallback-Slot (Standard: Playlist mit den meisten Items)}')]
#[Description('Konfiguriert eine Station für den lokalen Docker-Liquidsoap-Test (Output, Rundown, .env)')]
class PrepareLocalStream extends Command
{
    public function handle(RundownGeneratorService $generator): int
    {
        $station = $this->resolveStation();

        if (! $station) {
            $this->error('Keine Station gefunden. Lege zuerst eine Station an.');

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
        $this->info('✓ Lokaler Stream vorbereitet.');
        $this->line('  Hören auf  : http://localhost:8010/'.$station->slug.'  (Icecast, z.B. in VLC)');
        $this->line('  Rundowns   : '.$rundownCount.' Stunde(n) für heute generiert');
        $this->line('  docker/.env: geschrieben (SLUG, TOKEN, LIQUIDSOAP_API_URL)');

        if ($rundownCount === 0) {
            $this->newLine();
            $this->warn('Achtung: Für die aktuelle Stunde ist im Wochenraster keine Playlist hinterlegt.');
            $this->warn('Weise im Wochenraster der jetzigen Stunde eine Playlist zu und führe den Befehl erneut aus,');
            $this->warn('sonst liefert /next nur Stille.');
        }

        $this->newLine();
        $this->line('Jetzt starten:  <comment>./docker/run-local.ps1</comment>');

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

        $this->line('  → Icecast-Output gesetzt (host=icecast, mount=/'.$station->slug.')');
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

        $this->line('  → Stream-Record gesetzt (container=radioring-'.$station->slug.')');
    }

    /**
     * Generiert Rundowns für die aktuelle und die nächste Stunde.
     *
     * Für die aktuelle Stunde wird – falls kein Slot existiert – automatisch
     * ein Fallback-Slot im Wochenraster angelegt, damit lokal sofort Ton kommt.
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

            // Nur für die aktuelle Stunde einen Fallback-Slot anlegen
            if (! $slot && $offset === 0) {
                $playlist = $this->pickFallbackPlaylist($station);

                if (! $playlist) {
                    $this->warn('  → Keine Playlist mit Items vorhanden – kann keinen Fallback-Slot anlegen.');

                    continue;
                }

                $slot = $station->hourGridSlots()->create([
                    'weekday' => $weekday,
                    'hour' => $moment->hour,
                    'playlist_id' => $playlist->id,
                ]);
                $slot->load('playlist.items.mediaFile');

                $this->line("  → Fallback-Slot angelegt: jetzt → Playlist '{$playlist->name}'");
            }

            if (! $slot) {
                continue;
            }

            try {
                $generator->generate($station, $slot, $date, force: true);
                $count++;
                $this->line('  → Rundown generiert für '.$date->toDateString().' '.$moment->hour.':00');
            } catch (\Throwable $e) {
                $this->warn('  → Rundown für '.$moment->hour.':00 fehlgeschlagen: '.$e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Wählt die Fallback-Playlist: explizit per --playlist oder die mit den meisten Items.
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

        $this->line('  → Liquidsoap-State zurückgesetzt (Position 0)');
    }

    /**
     * Stellt sicher, dass die App-.env LIQUIDSOAP_API_URL enthält, damit das
     * generierte .liq-Script die vom Container erreichbare URL einbettet.
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

        // config:clear nur außerhalb von Tests – sonst reconnectet Laravel auf
        // eine frische :memory:-DB und bricht nachfolgende Tests ab.
        if (! app()->runningUnitTests()) {
            $this->call('config:clear');
        }

        $this->line('  → App-.env: '.$line);
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

        $content = "# Auto-generiert von radioring:prepare-local-stream\n"
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

        $this->line('  → docker/.env: Redis-Relay verdrahtet (REDIS_HOST='.$redisHost.', Channel='.config('radioring.control_channel').')');
    }
}
