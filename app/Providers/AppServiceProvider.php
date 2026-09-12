<?php

namespace App\Providers;

use App\Contracts\ContainerServiceInterface;
use App\Models\Setting;
use App\Services\DockerService;
use App\Services\PortainerService;
use App\Support\StaleTransactionGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Closure statt Class-String, damit config() im Test greift und ein Mock des
        // Interfaces weiterhin sauber ueberschreibt. Ein unbekannter Wert faellt bewusst
        // auf den Docker-Treiber zurueck, statt den Boot zu verhindern.
        $this->app->bind(ContainerServiceInterface::class, fn () => match ((string) config('radioring.container_driver')) {
            'portainer' => new PortainerService,
            default => new DockerService,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Queue::looping(function (): void {
            // A worker process keeps its database connection for its whole life, so a
            // connection left in a broken transaction state would take every following
            // job with it. Checked before each pull, which is the first thing that
            // would run into it.
            StaleTransactionGuard::reset();

            // Settings are memoised per process. A queue worker lives for many jobs, so
            // the memo has to be dropped between them. Otherwise a mode switched in the
            // admin area would not reach running workers until they restart.
            Setting::flushMemo();
        });

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
