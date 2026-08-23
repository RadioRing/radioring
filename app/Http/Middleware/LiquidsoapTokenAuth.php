<?php

namespace App\Http\Middleware;

use App\Models\Station;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LiquidsoapTokenAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response('Unauthorized', 401);
        }

        $slug = $request->route('slug');
        $station = Station::where('slug', $slug)->first();

        if (! $station || ! hash_equals($station->api_token, $token)) {
            return response('Unauthorized', 401);
        }

        $request->attributes->set('station', $station);

        return $next($request);
    }
}
