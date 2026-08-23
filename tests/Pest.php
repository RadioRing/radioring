<?php

use App\Enums\AppMode;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Switches the instance into cloud mode for the current test.
 *
 * The mode lives in the database so it can be changed at runtime; routes are registered
 * unconditionally and guard the mode themselves, so nothing needs re-registering here.
 */
function useCloudMode(): void
{
    AppMode::switchTo(AppMode::Cloud);
    Setting::flushMemo();
}

function useStandaloneMode(): void
{
    AppMode::switchTo(AppMode::Standalone);
    Setting::flushMemo();
}

/**
 * Signierte Auslieferungs-URL, wie sie der /next-Endpunkt an Liquidsoap gibt.
 *
 * @param  array<string, mixed>  $parameters
 */
function signedDeliveryUrl(string $route, array $parameters, ?int $ttlSeconds = null): string
{
    return URL::temporarySignedRoute(
        $route,
        now()->addSeconds($ttlSeconds ?? 3600),
        $parameters,
        absolute: false,
    );
}
