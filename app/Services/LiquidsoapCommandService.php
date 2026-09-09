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
     * Springt zum nächsten Track (request.dynamic-Source "radioring").
     *
     * $leadSeconds verschiebt den Schnitt in die Zukunft: Der Container plant ihn dann
     * selbst und blendet so aus, dass der Cut GENAU nach dieser Zeit liegt. So faded ein
     * um 14:58 gestarteter Titel vor 15:00:00 aus, statt erst danach. 0 = sofort.
     */
    public function skip(Station $station, float $leadSeconds = 0.0): bool
    {
        return $this->publish($station, 'skip', ['lead' => round(max(0.0, $leadSeconds), 2)]);
    }

    /**
     * Stoppt die Wiedergabe (Output).
     */
    public function stop(Station $station): bool
    {
        return $this->publish($station, 'stop');
    }

    /**
     * Restarts Liquidsoap without touching the container. The supervisor refetches script
     * and preset while doing so, which is how a configuration change takes effect.
     */
    public function restart(Station $station): bool
    {
        return $this->publish($station, 'restart');
    }

    /**
     * @param  array<string, mixed>  $extra  Zusätzliche Felder der Befehls-Nachricht.
     */
    protected function publish(Station $station, string $command, array $extra = []): bool
    {
        $containerName = $station->stream?->container_name ?? 'radioring-'.$station->slug;
        $channel = (string) config('radioring.control_channel');

        $payload = json_encode([
            'command' => $command,
            'container_name' => $containerName,
            ...$extra,
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
