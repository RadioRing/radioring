<?php

namespace App\Services;

use App\Models\Station;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Schickt Live-Befehle an den Liquidsoap-Container einer Station über einen
 * Redis-pub/sub-Kanal. Der Container subscribt darauf und leitet sie per Telnet
 * an Liquidsoap weiter (siehe docker/liquidsoap-station/entrypoint.sh).
 */
class LiquidsoapCommandService
{
    /**
     * Springt sofort zum nächsten Track (request.dynamic-Source "radioring").
     */
    public function skip(Station $station): bool
    {
        return $this->publish($station, 'skip');
    }

    /**
     * Stoppt die Wiedergabe (Output).
     */
    public function stop(Station $station): bool
    {
        return $this->publish($station, 'stop');
    }

    /**
     * Startet den Liquidsoap-Prozess im Container neu (lädt das Script neu).
     */
    public function restart(Station $station): bool
    {
        return $this->publish($station, 'restart');
    }

    protected function publish(Station $station, string $command): bool
    {
        $containerName = $station->stream?->container_name ?? 'radioring-'.$station->slug;
        $channel = (string) config('radioring.control_channel');

        $payload = json_encode([
            'command' => $command,
            'container_name' => $containerName,
        ], JSON_THROW_ON_ERROR);

        try {
            $receivers = $this->publishRaw($channel, $payload);

            Log::info("LiquidsoapCommand '{$command}' published", [
                'channel' => $channel,
                'container_name' => $containerName,
                'receivers' => $receivers,
            ]);

            if ($receivers === 0) {
                Log::warning("LiquidsoapCommand '{$command}': kein Subscriber auf '{$channel}' – lauscht der Container-Relay (REDIS_HOST/CONTROL_CHANNEL/CONTAINER_NAME)?");
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("LiquidsoapCommand '{$command}' für {$containerName} fehlgeschlagen: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Published OHNE den Laravel-Key-Prefix. phpredis hängt OPT_PREFIX
     * (z. B. "laravel-database-") auch an pub/sub-Channels an – der Container lauscht
     * aber auf den rohen Channelnamen. Ohne dieses Strippen träfen sie sich nie.
     *
     * @return int Anzahl der Subscriber, die die Nachricht erhalten haben.
     */
    protected function publishRaw(string $channel, string $payload): int
    {
        $connection = Redis::connection();
        $client = $connection->client();

        if ($client instanceof \Redis) {
            $previous = (string) $client->getOption(\Redis::OPT_PREFIX);
            $client->setOption(\Redis::OPT_PREFIX, '');

            try {
                return (int) $client->publish($channel, $payload);
            } finally {
                $client->setOption(\Redis::OPT_PREFIX, $previous);
            }
        }

        return (int) $connection->publish($channel, $payload);
    }
}
