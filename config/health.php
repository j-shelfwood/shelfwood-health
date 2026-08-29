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
    | Route
    |--------------------------------------------------------------------------
    |
    | Set 'enabled' => false to register nothing and wire the controller up
    | yourself (different prefix, extra middleware, auth, ...).
    |
    | NOTE: unauthenticated by default, matching the existing
    | prj-more-apartments endpoint. `meta` can carry detail you may not want
    | public (queue depths, versions) - worth re-taking that decision per app.
    |
    */
    'route' => [
        'enabled' => true,
        'uri' => 'api/v1/health',
        'name' => 'api.health',
        'middleware' => ['throttle:health'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Instance Identity
    |--------------------------------------------------------------------------
    |
    | The "instance" field. Null derives it from config('app.url')'s host.
    | Multi-instance apps bind their own Shelfwood\Health\Contracts\
    | InstanceIdentifier instead (prj-more-apartments uses Instance::id()).
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
