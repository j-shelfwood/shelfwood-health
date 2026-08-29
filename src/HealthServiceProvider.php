<?php

namespace Shelfwood\Health;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Shelfwood\Health\Contracts\InstanceIdentifier;
use Shelfwood\Health\Http\Controllers\HealthController;
use Shelfwood\Health\Support\ConfigInstanceIdentifier;

class HealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/health.php', 'health');

        // Bound only if the app has not already bound its own - an app using
        // shelfwood/instance-config binds a resolver returning Instance::id().
        $this->app->bindIf(InstanceIdentifier::class, ConfigInstanceIdentifier::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/health.php' => config_path('health.php'),
        ], 'health-config');

        $this->registerRateLimiter();

        if (config('health.route.enabled', true)) {
            $this->registerRoute();
        }
    }

    /**
     * Register a 'health' limiter unless the app defines one.
     *
     * The route middleware references throttle:health; without this, a fresh
     * install 500s on a limiter that does not exist.
     */
    protected function registerRateLimiter(): void
    {
        if (RateLimiter::limiter('health') !== null) {
            return;
        }

        $attempts = (int) config('health.rate_limit.attempts', 60);
        $perMinutes = (int) config('health.rate_limit.per_minutes', 1);

        RateLimiter::for('health', fn ($request) => Limit::perMinutes($perMinutes, $attempts)
            ->by($request->ip()));
    }

    protected function registerRoute(): void
    {
        Route::middleware(config('health.route.middleware', []))
            ->get(config('health.route.uri', 'api/v1/health'), HealthController::class)
            ->name(config('health.route.name', 'api.health'));
    }
}
