<?php

namespace Shelfwood\Health;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Shelfwood\Health\Checks\CacheHealthCheck;
use Shelfwood\Health\Checks\DatabaseHealthCheck;
use Shelfwood\Health\Checks\DatabaseWriteHealthCheck;
use Shelfwood\Health\Checks\MattermostHealthCheck;
use Shelfwood\Health\Checks\MetricsCacheHealthCheck;
use Shelfwood\Health\Checks\RedisHealthCheck;
use Shelfwood\Health\Checks\SentryHealthCheck;
use Shelfwood\Health\Checks\SessionHealthCheck;
use Shelfwood\Health\Checks\StorageHealthCheck;
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

        $this->registerChecks();
    }

    /**
     * Bind the shipped checks with their config.
     *
     * Bindings are the primary path for explicit config. Since v1.0.1 the
     * checks themselves also fall back to live config on null args
     * (config('database.default') etc.), because container resolution can
     * bypass these bindings: a class_alias shim resolves against the
     * canonical class name, not the alias, and before v1.0.1 that produced
     * confident nonsense against hardcoded defaults.
     *
     * bindIf throughout, so an app can override any single binding.
     */
    protected function registerChecks(): void
    {
        $env = fn () => $this->app->environment();

        $this->app->bindIf(DatabaseHealthCheck::class, fn () => new DatabaseHealthCheck(
            defaultConnection: config('database.default'),
            environment: $env(),
            connectionConfig: config('database.connections.'.config('database.default')),
            databasePath: database_path('database.sqlite'),
        ));

        $this->app->bindIf(DatabaseWriteHealthCheck::class, fn () => new DatabaseWriteHealthCheck(
            defaultConnection: config('database.default'),
            databasePath: database_path('database.sqlite'),
        ));

        $this->app->bindIf(RedisHealthCheck::class, fn () => new RedisHealthCheck(
            environment: $env(),
            cacheDriver: config('cache.default'),
            redisHost: config('database.redis.default.host'),
            redisPort: config('database.redis.default.port'),
        ));

        $this->app->bindIf(CacheHealthCheck::class, fn () => new CacheHealthCheck(
            cacheDriver: config('cache.default'),
            environment: $env(),
        ));

        $this->app->bindIf(SessionHealthCheck::class, fn () => new SessionHealthCheck(
            sessionDriver: config('session.driver'),
            sessionConnection: config('session.connection'),
            environment: $env(),
        ));

        $this->app->bindIf(StorageHealthCheck::class, fn () => new StorageHealthCheck(
            disksToCheck: config('health.storage_disks', ['local', 'public']),
            environment: $env(),
        ));

        $this->app->bindIf(MetricsCacheHealthCheck::class, fn () => new MetricsCacheHealthCheck(
            environment: $env(),
        ));

        $this->app->bindIf(SentryHealthCheck::class, fn () => new SentryHealthCheck(
            sentryDsn: config('sentry.dsn'),
            sentryEnvironment: config('sentry.environment'),
            environment: $env(),
        ));

        $this->app->bindIf(MattermostHealthCheck::class, fn () => new MattermostHealthCheck(
            environment: $env(),
            mattermostWebhookUrl: config('services.mattermost.webhook_url'),
        ));
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
