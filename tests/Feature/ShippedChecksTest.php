<?php

use Shelfwood\Health\Checks\CacheHealthCheck;
use Shelfwood\Health\Checks\DatabaseHealthCheck;
use Shelfwood\Health\Checks\DatabaseWriteHealthCheck;
use Shelfwood\Health\Checks\SessionHealthCheck;
use Shelfwood\Health\Checks\StorageHealthCheck;

/**
 * These checks all take nullable constructor args. An unbound check resolves
 * with all-null config and reports confident nonsense rather than erroring, so
 * "it resolves and returns a result" is the assertion that matters most here.
 */

it('resolves every shipped check from the container', function () {
    $checks = [
        DatabaseHealthCheck::class,
        DatabaseWriteHealthCheck::class,
        CacheHealthCheck::class,
        SessionHealthCheck::class,
        StorageHealthCheck::class,
    ];

    foreach ($checks as $class) {
        expect(app($class))->toBeInstanceOf($class);
    }
});

it('binds real config rather than nulls', function () {
    // The failure mode this guards: an unbound DatabaseHealthCheck falls back
    // to 'sqlite' and reports a connection it never verified.
    config()->set('database.default', 'testing');

    $result = app(DatabaseHealthCheck::class)->run();

    // 'driver' echoes the injected connection name. Unbound, it would fall back
    // to the literal 'sqlite' regardless of what the app is configured to use.
    expect($result->key)->toBe('database')
        ->and($result->meta['driver'])->toBe('testing');
});

it('runs the shipped checks through the endpoint', function () {
    config()->set('health.checks', [
        DatabaseHealthCheck::class,
        CacheHealthCheck::class,
        SessionHealthCheck::class,
    ]);

    $r = $this->getJson('/api/v1/health');

    expect($r->json('summary.total'))->toBe(3)
        ->and(collect($r->json('checks'))->pluck('key')->all())
        ->toBe(['database', 'cache', 'session']);
});

it('lets an app override a shipped binding', function () {
    // bindIf, so this must win.
    app()->bind(CacheHealthCheck::class, fn () => new CacheHealthCheck(
        cacheDriver: 'custom-driver',
        environment: 'production',
    ));

    config()->set('health.checks', [CacheHealthCheck::class]);

    $meta = $this->getJson('/api/v1/health')->json('checks.0');

    expect($meta['key'])->toBe('cache');
});
