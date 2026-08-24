<?php

namespace App\Services;

use App\Contracts\ContainerServiceInterface;
use App\Models\Station;
use App\Services\Concerns\ManagesIcecastSidecar;
use App\Services\Docker\DockerConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drives the station containers through the Docker Engine API, either via a socket proxy
 * (the documented default) or a mounted unix socket.
 *
 * SECURITY INVARIANT: the container spec is built entirely from server-side config. No
 * station-owned field ever reaches HostConfig, Binds, Image or Privileged. Env and Labels
 * carry only the slug, the API token and config values. Anything that can reach the Docker
 * API can start a privileged container, so this boundary is what keeps a compromised
 * station from becoming host root. See SECURITY.md.
 */
class DockerService implements ContainerServiceInterface
{
    use ManagesIcecastSidecar;

    private ?DockerConnection $connection = null;

    private function connection(): DockerConnection
    {
        return $this->connection ??= DockerConnection::fromDockerHost(
            (string) config('radioring.docker.host'),
            (string) config('radioring.docker.api_version'),
        );
    }

    protected function client(?int $timeout = null): PendingRequest
    {
        $connection = $this->connection();

        $request = Http::acceptJson()
            ->baseUrl($connection->baseUrl)
            ->connectTimeout((int) config('radioring.docker.connect_timeout', 5))
            ->timeout($timeout ?? (int) config('radioring.docker.timeout', 30));

        if ($connection->curlOptions !== []) {
            $request = $request->withOptions(['curl' => $connection->curlOptions]);
        }

        return $request;
    }

    public function isConfigured(): bool
    {
        $connection = $this->connection();

        if ($connection->baseUrl === '') {
            return false;
        }

        if (! $connection->usesSocket()) {
            return true;
        }

        // Without ext-curl Guzzle silently falls back to the stream handler and ignores
        // CURLOPT_UNIX_SOCKET_PATH, which would quietly connect to a host called
        // "localhost" instead of failing.
        if (! extension_loaded('curl')) {
            Log::error('Docker: der Unix-Socket-Modus benoetigt die PHP-Erweiterung curl.');

            return false;
        }

        return file_exists((string) $connection->socketPath);
    }

    public function startStationContainer(Station $station): bool
    {
        $name = $this->containerName($station);

        try {
            $pulled = $this->pullImage();

            // Live-Harbor-Zugangsdaten (inkl. eindeutigem Port) provisionieren, bevor der
            // Container das Script zieht.
            $stream = $station->ensureStream();

            // The sidecar has to be up before Liquidsoap. output.icecast is fallible and
            // would retry, but without a target the station starts with a gap.
            $this->syncIcecastSidecar($station);

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

            // Den Live-Harbor-Port direkt auf dem Host veroeffentlichen (Klartext-Ingest,
            // ohne Traefik). Nur wenn eine Stream-Domain gesetzt ist (sonst lokal/Dev).
            if ($this->streamPublished() && $stream->live_port) {
                $port = (int) $stream->live_port;
                $payload['ExposedPorts'] = ["{$port}/tcp" => new \stdClass];
                $payload['HostConfig']['PortBindings'] = [
                    "{$port}/tcp" => [['HostPort' => (string) $port]],
                ];
            }

            // Optional einem benannten Netz beitreten, damit der Container die App intern
            // erreicht statt per Hairpin ueber die oeffentliche URL. Leer = Default-Bridge.
            if ($network = (string) config('radioring.docker.station_network')) {
                $payload['NetworkingConfig'] = [
                    'EndpointsConfig' => [$network => new \stdClass],
                ];
            }

            $response = $this->client()->post('/containers/create?'.http_build_query(['name' => $name]), $payload);

            $containerId = $response->json('Id');

            if (! $containerId) {
                // 409 = existiert bereits, dann die bestehende ID holen.
                $containerId = $this->containerIdByName($name);
            }

            if (! $containerId) {
                Log::error("Docker: Container {$name} konnte nicht angelegt werden. ".$this->describe($response)
                    .($pulled ? '' : ' Der Image-Pull war zuvor fehlgeschlagen.'));

                return false;
            }

            $start = $this->client()->post("/containers/{$containerId}/start");

            // 204 = gestartet, 304 = lief bereits.
            if ($start->failed() && $start->status() !== 304) {
                Log::error("Docker: Start von {$name} fehlgeschlagen. ".$this->describe($start));

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Docker: Fehler beim Start von {$name}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Stoppt und entfernt den Container. Ein bereits verschwundener Container gilt als
     * Erfolg, damit ein Stop wiederholbar ist und die Zeile nicht in "running" haengt.
     */
    public function stopStationContainer(Station $station): bool
    {
        $name = $this->containerName($station);

        // A leftover Icecast would keep the station looking reachable while nothing
        // is being sent to it.
        $this->removeIcecastSidecar($station);

        try {
            $response = $this->client()->delete("/containers/{$name}?force=true");

            if ($response->failed() && $response->status() !== 404) {
                Log::warning("Docker: Stop von {$name} meldete einen Fehler. ".$this->describe($response));
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Docker: Stop von {$name} fehlgeschlagen: ".$e->getMessage());

            return false;
        }
    }

    public function restartStationContainer(Station $station): bool
    {
        $name = $this->containerName($station);

        // Restarting is the documented point at which output changes take effect, so a
        // freshly enabled internal output gets its sidecar here, and a disabled one loses it.
        $this->syncIcecastSidecar($station);

        try {
            $response = $this->client()->post("/containers/{$name}/restart");

            if ($response->failed() && $response->status() !== 304) {
                Log::error("Docker: Restart von {$name} fehlgeschlagen. ".$this->describe($response));

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Docker: Restart von {$name} fehlgeschlagen: ".$e->getMessage());

            return false;
        }
    }

    public function containerState(Station $station): ?string
    {
        $name = $this->containerName($station);

        try {
            $response = $this->client()->get("/containers/{$name}/json");

            if ($response->status() === 404) {
                return 'not_found';
            }

            if ($response->failed()) {
                return null;
            }

            $state = $response->json('State.Status');

            return $state ? strtolower((string) $state) : null;
        } catch (\Throwable $e) {
            Log::error("Docker: Status von {$name} nicht abrufbar: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Zieht das Station-Image.
     *
     * Achtung: POST /images/create antwortet mit HTTP 200 und streamt anschliessend
     * JSON-Zeilen. Ein fehlgeschlagener Pull steckt als {"error": ...} IM BODY, nicht im
     * Statuscode. Der Body ist kein einzelnes JSON-Dokument, $response->json() liefert
     * hier also null.
     *
     * Bewusst "best effort": liegt das Image lokal bereits vor, darf ein fehlgeschlagener
     * Pull den Start nicht verhindern (air-gapped Hosts). Der Rueckgabewert dient nur der
     * Fehlermeldung, falls das anschliessende Anlegen scheitert.
     */
    protected function pullImage(?string $image = null): bool
    {
        $image = $image ?: (string) config('radioring.station_image');
        [$fromImage, $tag] = $this->splitImage($image);

        $request = $this->client((int) config('radioring.docker.pull_timeout', 600));

        $username = (string) config('radioring.registry_username');
        $password = (string) config('radioring.registry_password');

        if ($username !== '' && $password !== '') {
            $request = $request->withHeaders(['X-Registry-Auth' => $this->registryAuth($fromImage, $username, $password)]);
        }

        try {
            $response = $request->post('/images/create?'.http_build_query([
                'fromImage' => $fromImage,
                'tag' => $tag,
            ]));
        } catch (\Throwable $e) {
            Log::warning("Docker: Image-Pull fuer {$image} fehlgeschlagen: ".$e->getMessage());

            return false;
        }

        if ($response->failed()) {
            Log::warning("Docker: Image-Pull fuer {$image} meldete HTTP {$response->status()}. ".$this->describe($response));

            return false;
        }

        if ($error = $this->pullStreamError($response->body())) {
            Log::warning("Docker: Image-Pull fuer {$image} fehlgeschlagen: {$error}");

            return false;
        }

        return true;
    }

    /**
     * Sucht die Fehlerzeile im JSON-Lines-Body eines Pulls.
     */
    protected function pullStreamError(string $body): ?string
    {
        $failure = null;

        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $frame = json_decode($line, true);

            if (is_array($frame) && isset($frame['error'])) {
                $failure = (string) $frame['error'];
            }
        }

        return $failure;
    }

    /**
     * X-Registry-Auth ist laut Docker-Spezifikation base64url-kodiert.
     */
    protected function registryAuth(string $fromImage, string $username, string $password): string
    {
        $json = json_encode([
            'username' => $username,
            'password' => $password,
            'serveraddress' => explode('/', $fromImage)[0],
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Docker legt die Fehlerursache als {"message": "..."} in den Body. Ohne das ist
     * "warum startet der Container nicht" praktisch nicht zu beantworten.
     */
    protected function describe(Response $response): string
    {
        $message = $response->json('message');

        return "HTTP {$response->status()}".($message ? ": {$message}" : '');
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function splitImage(string $image): array
    {
        $pos = strrpos($image, ':');

        // Kein Tag, oder ':' gehoert zum Host:Port (vor einem '/')
        if ($pos === false || strpos($image, '/', $pos) !== false) {
            return [$image, 'latest'];
        }

        return [substr($image, 0, $pos), substr($image, $pos + 1)];
    }

    protected function containerIdByName(string $name): ?string
    {
        try {
            $response = $this->client()->get("/containers/{$name}/json");

            return $response->successful() ? $response->json('Id') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function containerName(Station $station): string
    {
        return $station->stream?->container_name ?? 'radioring-'.$station->slug;
    }

    /**
     * Wird der Live-Harbor auf dem Host veroeffentlicht? Nur wenn eine Stream-Domain
     * konfiguriert ist (sonst lokal/Dev ohne Veroeffentlichung).
     */
    protected function streamPublished(): bool
    {
        return (string) config('radioring.stream.domain') !== '';
    }

    /**
     * Creates and starts the sidecar through the Docker Engine API.
     */
    protected function createIcecastSidecar(Station $station, string $host): bool
    {
        $name = $station->icecastContainerName();

        try {
            $pulled = $this->pullImage((string) config('radioring.icecast.image'));

            $response = $this->client()->post(
                '/containers/create?'.http_build_query(['name' => $name]),
                $this->icecastPayload($station, $host),
            );

            $containerId = $response->json('Id') ?: $this->containerIdByName($name);

            if (! $containerId) {
                Log::error("Docker: Icecast-Sidecar {$name} konnte nicht angelegt werden. ".$this->describe($response)
                    .($pulled ? '' : ' Der Image-Pull war zuvor fehlgeschlagen.'));

                return false;
            }

            $start = $this->client()->post("/containers/{$containerId}/start");

            if ($start->failed() && $start->status() !== 304) {
                Log::error("Docker: Start des Icecast-Sidecars {$name} fehlgeschlagen. ".$this->describe($start));

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Docker: Fehler beim Start des Icecast-Sidecars {$name}: ".$e->getMessage());

            return false;
        }
    }

    protected function deleteIcecastSidecar(Station $station): bool
    {
        $name = $station->icecastContainerName();

        try {
            $response = $this->client()->delete("/containers/{$name}?force=true");

            if ($response->failed() && $response->status() !== 404) {
                Log::warning("Docker: Entfernen des Icecast-Sidecars {$name} meldete einen Fehler. ".$this->describe($response));

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Docker: Entfernen des Icecast-Sidecars {$name} fehlgeschlagen: ".$e->getMessage());

            return false;
        }
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
