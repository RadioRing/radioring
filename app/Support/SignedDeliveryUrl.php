<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Builds the signed, time limited URLs the station container downloads audio from.
 *
 * A signature covers this one URL and expires. The api_token used to hang here as a query
 * parameter, which put it into every proxy log, and whoever read it there could fetch
 * /script with it, Icecast and harbor passwords included.
 *
 * Signed RELATIVELY on purpose: Laravel checks an absolute signature against the request
 * host, but the app is reachable under APP_URL or internally under LIQUIDSOAP_API_URL.
 */
class SignedDeliveryUrl
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function for(string $route, array $parameters): string
    {
        $ttl = (int) config('radioring.delivery_url_ttl_seconds', 21600);

        $relative = URL::temporarySignedRoute($route, now()->addSeconds($ttl), $parameters, absolute: false);

        $base = rtrim((string) (config('radioring.liquidsoap_api_url') ?: config('app.url')), '/');

        return $base.$relative;
    }
}
