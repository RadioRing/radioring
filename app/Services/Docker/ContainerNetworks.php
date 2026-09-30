<?php

namespace App\Services\Docker;

/**
 * Which named networks the containers RadioRing creates join.
 *
 * The layout keeps anything that faces listeners away from the internal services:
 *
 * | Container     | Networks                  | Why                                          |
 * |---------------|---------------------------|----------------------------------------------|
 * | Station       | station + stream          | app/Redis internally, own Icecast by name    |
 * | Icecast       | stream + web              | Liquidsoap sends to it, Traefik routes to it |
 *
 * The Icecast sidecar is publicly reachable, so it never joins the station network:
 * database, Redis and the station containers' other neighbours stay out of its reach.
 *
 * Several endpoints in one create call need Docker API 1.44 (Docker Engine 25).
 */
final class ContainerNetworks
{
    /**
     * @return list<string>
     */
    public static function forStation(): array
    {
        return self::unique([
            config('radioring.docker.station_network'),
            config('radioring.docker.stream_network'),
        ]);
    }

    /**
     * @return list<string>
     */
    public static function forIcecast(): array
    {
        return self::unique([
            config('radioring.docker.stream_network'),
            config('radioring.icecast.web_network'),
        ]);
    }

    /**
     * The NetworkingConfig part of a create payload, or an empty array for the default
     * bridge.
     *
     * @param  list<string>  $networks
     * @return array{NetworkingConfig?: array{EndpointsConfig: array<string, \stdClass>}}
     */
    public static function payload(array $networks): array
    {
        if ($networks === []) {
            return [];
        }

        return [
            'NetworkingConfig' => [
                'EndpointsConfig' => array_fill_keys($networks, new \stdClass),
            ],
        ];
    }

    /**
     * Empty names drop out, identical ones collapse into one endpoint.
     *
     * @param  array<int, mixed>  $networks
     * @return list<string>
     */
    private static function unique(array $networks): array
    {
        $names = array_map(fn (mixed $network): string => (string) $network, $networks);

        return array_values(array_unique(array_filter($names, fn (string $network): bool => $network !== '')));
    }
}
