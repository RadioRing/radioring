<?php

namespace App\Services;

use App\Contracts\ContainerServiceInterface;
use App\Models\Station;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PortainerService implements ContainerServiceInterface
{
    protected string $endpoint;

    protected string $token;

    protected string $environment;

    public function __construct()
    {
        $this->endpoint = (string) config('services.portainer.endpoint');
        $this->token = (string) config('services.portainer.token');
        $this->environment = (string) config('services.portainer.environment');
    }

    protected function client(): PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key' => $this->token,
        ])->baseUrl(rtrim($this->endpoint, '/'));
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->token !== '';
    }

    public function startStationContainer(Station $station): bool
    {
        $name = $this->containerName($station);

        try {
            $this->pullImage();

            // Live-Harbor-Zugangsdaten (inkl. eindeutigem Port) provisionieren, bevor der
            // Container das Script zieht.
            $stream = $station->ensureStream();

            $payload = [
                'Image' => config('radioring.station_image'),
                'Env' => $this->envVars($station),
                'Labels' => [
                    'managed_by' => (string) config('radioring.station_managed_by', 'radioring'),
                    'station_slug' => $station->slug,
                ],
                'HostConfig' => [
                    'RestartPolicy' => ['Name' => 'unless-stopped'],
                ],
            ];

            // Den Live-Harbor-Port direkt auf dem Host veröffentlichen (Klartext-Ingest,
            // ohne Traefik). Nur wenn eine Stream-Domain gesetzt ist (sonst lokal/Dev).
            if ($this->streamPublished() && $stream->live_port) {
                $port = (int) $stream->live_port;
                $payload['ExposedPorts'] = ["{$port}/tcp" => new \stdClass];
                $payload['HostConfig']['PortBindings'] = [
                    "{$port}/tcp" => [['HostPort' => (string) $port]],
                ];
            }

            $response = $this->client()->post(
                "/endpoints/{$this->environment}/docker/containers/create?name={$name}",
                $payload
            );

            $containerId = $response->json('Id');

            if (! $containerId) {
                // 409 = existiert bereits → bestehende ID holen
                $containerId = $this->containerIdByName($name);
            }

            if (! $containerId) {
                Log::error("Portainer: konnte Container-ID für {$name} nicht ermitteln.");

                return false;
            }

            $start = $this->client()->post("/endpoints/{$this->environment}/docker/containers/{$containerId}/start");

            if ($start->failed() && $start->status() !== 304) {
                Log::error("Portainer: Start von {$name} fehlgeschlagen (HTTP {$start->status()}).");

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Portainer: Fehler beim Start von {$name}: ".$e->getMessage());

            return false;
        }
    }

    public function stopStationContainer(Station $station): bool
    {
        $name = $this->containerName($station);

        try {
            $this->client()->delete("/endpoints/{$this->environment}/docker/containers/{$name}?force=true");

            return true;
        } catch (\Throwable $e) {
            Log::error("Portainer: Stop von {$name} fehlgeschlagen: ".$e->getMessage());

            return false;
        }
    }

    public function restartStationContainer(Station $station): bool
    {
        $name = $this->containerName($station);

        try {
            $response = $this->client()->post("/endpoints/{$this->environment}/docker/containers/{$name}/restart");

            return $response->successful() || $response->status() === 304;
        } catch (\Throwable $e) {
            Log::error("Portainer: Restart von {$name} fehlgeschlagen: ".$e->getMessage());

            return false;
        }
    }

    public function containerState(Station $station): ?string
    {
        $name = $this->containerName($station);

        try {
            $response = $this->client()->get("/endpoints/{$this->environment}/docker/containers/{$name}/json");

            if ($response->status() === 404) {
                return 'not_found';
            }

            if ($response->failed()) {
                return null;
            }

            $state = $response->json('State.Status');

            return $state ? strtolower((string) $state) : null;
        } catch (\Throwable $e) {
            Log::error("Portainer: Status von {$name} nicht abrufbar: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Zieht das Station-Image auf dem Ziel-Endpoint (best effort).
     */
    protected function pullImage(): void
    {
        $image = (string) config('radioring.station_image');
        [$fromImage, $tag] = $this->splitImage($image);

        $request = $this->client();

        $username = (string) config('radioring.registry_username');
        $password = (string) config('radioring.registry_password');

        if ($username !== '' && $password !== '') {
            $auth = base64_encode(json_encode([
                'username' => $username,
                'password' => $password,
                'serveraddress' => explode('/', $fromImage)[0],
            ], JSON_THROW_ON_ERROR));

            $request = $request->withHeaders(['X-Registry-Auth' => $auth]);
        }

        $response = $request->post(
            "/endpoints/{$this->environment}/docker/images/create?fromImage={$fromImage}&tag={$tag}"
        );

        if ($response->failed()) {
            Log::warning("Portainer: Image-Pull für {$image} meldete HTTP {$response->status()} (fahre fort).");
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function splitImage(string $image): array
    {
        $pos = strrpos($image, ':');

        // Kein Tag, oder ':' gehört zum Host:Port (vor einem '/')
        if ($pos === false || strpos($image, '/', $pos) !== false) {
            return [$image, 'latest'];
        }

        return [substr($image, 0, $pos), substr($image, $pos + 1)];
    }

    protected function containerIdByName(string $name): ?string
    {
        try {
            $response = $this->client()->get("/endpoints/{$this->environment}/docker/containers/{$name}/json");

            return $response->successful() ? $response->json('Id') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function containerName(Station $station): string
    {
        return $station->stream?->container_name ?? 'radioring-'.$station->slug;
    }

    /**
     * Wird der Live-Harbor öffentlich via Traefik veröffentlicht? Nur wenn eine
     * Stream-Domain konfiguriert ist (sonst lokal/Dev ohne Veröffentlichung).
     */
    protected function streamPublished(): bool
    {
        return (string) config('radioring.stream.domain') !== '';
    }

    /**
     * @return list<string>
     */
    protected function envVars(Station $station): array
    {
        $apiUrl = rtrim(config('radioring.liquidsoap_api_url') ?: config('app.url'), '/');
        $redis = config('database.redis.default');

        return [
            'API_URL='.$apiUrl,
            'SLUG='.$station->slug,
            'TOKEN='.$station->api_token,
            'SCRIPT_REFRESH=true',
            'CONTAINER_NAME='.$this->containerName($station),
            // Redis-Command-Relay: Container subscribt auf diesen Kanal
            'CONTROL_CHANNEL='.config('radioring.control_channel'),
            'REDIS_HOST='.(config('radioring.station_redis_host') ?: ($redis['host'] ?? '127.0.0.1')),
            'REDIS_PORT='.($redis['port'] ?? '6379'),
            'REDIS_PASSWORD='.($redis['password'] ?? ''),
            'REDIS_DB='.($redis['database'] ?? '0'),
        ];
    }
}
