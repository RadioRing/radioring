<?php

namespace App\Services\Concerns;

use App\Models\Station;
use Illuminate\Support\Facades\Log;

/**
 * Shared Icecast sidecar handling for the container drivers.
 *
 * When a sidecar should run, and what it looks like, is the same everywhere. Only the
 * API it is created through differs, which is what the two abstract methods cover.
 */
trait ManagesIcecastSidecar
{
    /**
     * Brings the sidecar into the state the station's outputs ask for.
     *
     * Always recreated rather than reused: mount, bitrate and password live in env and
     * labels, so an existing container may carry a stale configuration.
     */
    protected function syncIcecastSidecar(Station $station): bool
    {
        $output = $station->internalOutput();

        if (! $output || ! Station::internalStreamSupported()) {
            return $this->removeIcecastSidecar($station);
        }

        $host = $station->streamHost();
        $name = $station->icecastContainerName();

        // The slug ends up in a Traefik rule and in label names. Anything outside the
        // expected character set could define foreign routers there, so skip the sidecar
        // rather than build one from it.
        if ($host === null || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $station->slug)) {
            Log::warning("Icecast-Sidecar {$name} übersprungen: unbrauchbarer Slug oder fehlende Stream-Domain.");

            return false;
        }

        $this->removeIcecastSidecar($station);

        return $this->createIcecastSidecar($station, $host);
    }

    /**
     * Removes the sidecar. A container that is already gone counts as success.
     *
     * Stations without an internal output never had one, and would otherwise pay a
     * request that can only return 404 on every stop.
     */
    protected function removeIcecastSidecar(Station $station): bool
    {
        if (! $station->hasInternalOutput()) {
            return true;
        }

        return $this->deleteIcecastSidecar($station);
    }

    /**
     * Creates and starts the container through the driver's own API.
     */
    abstract protected function createIcecastSidecar(Station $station, string $host): bool;

    /**
     * Removes the container through the driver's own API.
     */
    abstract protected function deleteIcecastSidecar(Station $station): bool;

    /**
     * The sidecar's container spec, identical for every driver.
     *
     * @return array<string, mixed>
     */
    protected function icecastPayload(Station $station, string $host): array
    {
        return [
            'Image' => (string) config('radioring.icecast.image'),
            'Env' => $this->icecastEnvVars($station),
            'Labels' => $this->icecastLabels($station, $host),
            'HostConfig' => [
                'RestartPolicy' => ['Name' => 'unless-stopped'],
            ],
            'NetworkingConfig' => [
                'EndpointsConfig' => $this->icecastNetworks(),
            ],
        ];
    }

    /**
     * The station network, so Liquidsoap resolves the sidecar by container name, and the
     * proxy network, so Traefik sees it. Identical or empty names collapse into one.
     *
     * @return array<string, \stdClass>
     */
    protected function icecastNetworks(): array
    {
        $networks = [];

        foreach ([config('radioring.docker.station_network'), config('radioring.icecast.web_network')] as $network) {
            if (($network = (string) $network) !== '') {
                $networks[$network] = new \stdClass;
            }
        }

        return $networks;
    }

    /**
     * @return array<string, string>
     */
    protected function icecastLabels(Station $station, string $host): array
    {
        $router = 'radioring-icecast-'.$station->slug;

        return [
            'managed_by' => (string) config('radioring.station_managed_by', 'radioring'),
            'station_slug' => $station->slug,
            'traefik.enable' => 'true',
            'traefik.docker.network' => (string) config('radioring.icecast.web_network'),
            "traefik.http.routers.{$router}.rule" => 'Host(`'.$host.'`)',
            "traefik.http.routers.{$router}.entrypoints" => 'websecure',
            "traefik.http.routers.{$router}.tls.certresolver" => (string) config('radioring.icecast.cert_resolver'),
            "traefik.http.services.{$router}.loadbalancer.server.port" => '8000',
        ];
    }

    /**
     * @return list<string>
     */
    protected function icecastEnvVars(Station $station): array
    {
        $stream = $station->ensureStream();

        return [
            'ICECAST_SOURCE_PASSWORD='.$stream->icecast_password,
            'ICECAST_ADMIN_PASSWORD='.config('radioring.icecast.admin_password'),
            'ICECAST_HOSTNAME='.$station->streamHost(),
            'ICECAST_MAX_LISTENERS='.config('radioring.icecast.max_listeners'),
            'ICECAST_BURST_SIZE='.config('radioring.icecast.burst_size'),
        ];
    }
}
