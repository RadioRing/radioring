<?php

namespace App\Services;

use App\Models\Station;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reads the current listener count from a station's Icecast sidecar.
 *
 * Deliberately only the current value: a time series would need its own table and
 * retention. The request goes to the container name inside the Docker network, so it
 * needs no credentials and does not hairpin through Traefik.
 */
class IcecastStatusService
{
    /**
     * The call sits in the dashboard's render path, so a slow or dead sidecar must not
     * block the page.
     */
    private const TIMEOUT_SECONDS = 2;

    /**
     * Current listeners, or null when the figure cannot be determined (no internal
     * output, sidecar unreachable).
     */
    public function listeners(Station $station): ?int
    {
        $output = $station->internalOutput();

        if (! $output || ! Station::internalStreamSupported()) {
            return null;
        }

        $ttl = (int) config('radioring.icecast.status_ttl_seconds', 10);

        return Cache::remember(
            "icecast:listeners:{$station->id}",
            $ttl,
            fn (): ?int => $this->fetchListeners($station, $output->mountName()),
        );
    }

    private function fetchListeners(Station $station, string $mount): ?int
    {
        $url = 'http://'.$station->icecastContainerName().':8000/status-json.xsl';

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $sources = $response->json('icestats.source');

        if ($sources === null) {
            // Icecast is up but nobody is sending: the mount does not exist.
            return 0;
        }

        // One mount comes back as an object, several as a list.
        if (array_is_list($sources) === false) {
            $sources = [$sources];
        }

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            if ($this->matchesMount($source, $mount)) {
                return (int) ($source['listeners'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Icecast does not name the mount directly, only as part of the listenurl
     * ("http://station.example.com:8000/stream").
     *
     * @param  array<string, mixed>  $source
     */
    private function matchesMount(array $source, string $mount): bool
    {
        $path = parse_url((string) ($source['listenurl'] ?? ''), PHP_URL_PATH);

        return ltrim((string) $path, '/') === $mount;
    }
}
