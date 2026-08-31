<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Health Check Registry
    |--------------------------------------------------------------------------
    |
    | Classes extending Shelfwood\Health\HealthCheck, resolved from the
    | container. Order is preserved in the response.
    |
    | The package ships generic checks (database, cache, queue, ...); apps add
    | their own domain checks to this same list.
    |
    */
    'checks' => [
        // Shelfwood\Health\Checks\DatabaseHealthCheck::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Providers
    |--------------------------------------------------------------------------
    |
    | Classes implementing Contracts\MetricsProvider. Their merged map is
    | added to the response as `metrics` — business numbers with no status,
    | never affecting the overall result. Threshold via a check instead when
    | a number should be able to alert.
    |
    */
    'metrics' => [
        // App\Health\KpiMetrics::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | Set 'enabled' => false to register nothing and wire the controller up
    | yourself (different prefix, extra middleware, auth, ...).
    |
    | NOTE: unauthenticated by default, matching the existing
    | originating app's endpoint. `meta` can carry detail you may not want
    | public (queue depths, versions) - worth re-taking that decision per app.
    |
    */
    'route' => [
        'enabled' => true,
        'uri' => 'api/v1/health',
        'name' => 'api.health',
        'middleware' => ['throttle:health'],

        // Set (e.g. env('HEALTH_SECRET')) and add
        // Shelfwood\Health\Http\Middleware\VerifyHealthSecret to the
        // middleware above to gate the document with an x-cron-secret
        // header — the monitor's ops-health convention.
        'secret' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Instance Identity
    |--------------------------------------------------------------------------
    |
    | The "instance" field. Null derives it from config('app.url')'s host.
    | Multi-instance apps bind their own Shelfwood\Health\Contracts\
    | InstanceIdentifier instead (e.g. a multi-tenant app using Instance::id()).
    |
    */
    'instance' => env('HEALTH_INSTANCE'),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiter
    |--------------------------------------------------------------------------
    |
    | The package registers a 'health' limiter if the app has not defined one.
    |
    */
    'rate_limit' => [
        'attempts' => 60,
        'per_minutes' => 1,
    ],
];
