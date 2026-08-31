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

// Unbound (or class_alias-resolved) instances get all-null constructor args.
// They must fall back to live config, not report "not configured" nonsense —
// an alias-resolved RedisHealthCheck once flagged a whole estate degraded.
it('redis check falls back to live config when constructed with nulls', function () {
    config(['cache.default' => 'redis', 'database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 6379]);
    Illuminate\Support\Facades\Redis::shouldReceive('ping')->once()->andReturn('PONG');

    $result = (new Shelfwood\Health\Checks\RedisHealthCheck)->check();

    expect($result->status)->toBe(Shelfwood\Health\HealthCheckStatus::Healthy)
        ->and($result->meta['host'])->toBe('127.0.0.1');
});

it('redis check still warns on a genuinely non-redis cache driver', function () {
    config(['cache.default' => 'file']);

    $result = (new Shelfwood\Health\Checks\RedisHealthCheck)->check();

    expect($result->status)->toBe(Shelfwood\Health\HealthCheckStatus::Warning)
        ->and($result->meta['current_driver'])->toBe('file');
});

it('database check reads the configured default connection when unbound', function () {
    config(['database.default' => 'sqlite']);

    $result = (new Shelfwood\Health\Checks\DatabaseHealthCheck)->check();

    expect($result->message)->toContain('sqlite');
});
