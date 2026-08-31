<?php

namespace Shelfwood\Health\Checks;

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;
use Illuminate\Support\Facades\Redis;

class RedisHealthCheck extends HealthCheck
{
    /**
     * Create Redis health check.
     *
     * @param  string|null  $environment  Application environment
     * @param  string|null  $cacheDriver  Cache driver name
     * @param  string|null  $redisHost  Redis server host
     * @param  int|null  $redisPort  Redis server port
     *
     * @note All dependencies injected by service container
     */
    public function __construct(
        private ?string $environment = null,
        private ?string $cacheDriver = null,
        private ?string $redisHost = null,
        private ?int $redisPort = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return '🔄 Redis';
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        if ($this->environment === 'testing') {
            return HealthCheckResult::healthy($this->name(), 'Skipping in testing environment');
        }

        // Null args mean "not provided" (unbound or alias-resolved instance);
        // fall back to live config instead of reporting confident nonsense —
        // a null driver used to produce a false "not configured" warning that
        // marked every uptime monitor of an estate degraded.
        $driver = $this->cacheDriver ?? config('cache.default');

        if ($driver !== 'redis') {
            return HealthCheckResult::warning($this->name(), 'Redis not configured as cache driver', [
                'current_driver' => $driver,
            ]);
        }

        try {
            Redis::ping();

            return HealthCheckResult::healthy($this->name(), 'Connection successful', [
                'host' => $this->redisHost ?? config('database.redis.default.host'),
                'port' => $this->redisPort ?? config('database.redis.default.port'),
            ]);
        } catch (\Exception $e) {
            return HealthCheckResult::failed($this->name(), "Connection failed: {$e->getMessage()}");
        }
    }
}
