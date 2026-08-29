<?php

namespace Shelfwood\Health\Checks;

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Cache system health check with multi-driver validation.
 *
 * Validates cache driver connectivity and read/write operations.
 * Complements RedisHealthCheck by covering all Laravel cache drivers.
 * Critical for application performance and API rate limiting.
 *
 * Check outcomes:
 * - Failed: Cache driver inaccessible or read/write operations failed
 * - Warning: Array driver in production (no persistent caching)
 * - Healthy: Cache operations successful
 *
 * @see https://laravel.com/docs/11.x/cache
 * @see https://www.honeybadger.io/blog/caching-in-laravel/
 * @see https://kinsta.com/blog/laravel-caching/
 */
class CacheHealthCheck extends HealthCheck
{
    /**
     * Create cache health check.
     *
     * @param  string|null  $cacheDriver  Cache driver name
     * @param  string|null  $environment  Application environment
     */
    public function __construct(
        private ?string $cacheDriver = null,
        private ?string $environment = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return '⚡ Cache';
    }

    /**
     * Perform cache health check.
     *
     * Validation flow:
     * 1. Validate cache driver configured
     * 2. Test cache read/write operations
     * 3. Warn if array driver in production
     *
     * @return HealthCheckResult Status with cache metrics
     */
    #[\Override]
    public function check(): HealthCheckResult
    {
        $driver = $this->cacheDriver ?? config('cache.default', 'file');

        if ($driver === 'array' && in_array($this->environment, ['production', 'staging'])) {
            return HealthCheckResult::warning(
                $this->name(),
                'Array cache driver provides no persistent caching in production',
                [
                    'driver' => $driver,
                    'hint' => 'Consider using redis, memcached, or database driver',
                ]
            );
        }

        $validationResult = match ($driver) {
            'redis' => $this->validateRedisCache(),
            'database' => $this->validateDatabaseCache(),
            'file' => $this->validateFileCache(),
            'memcached' => $this->validateMemcachedCache(),
            'array' => ['valid' => true, 'message' => 'Array cache (no persistence)'],
            'null' => ['valid' => true, 'message' => 'Null cache (testing mode)'],
            default => ['valid' => false, 'message' => "Unknown cache driver: {$driver}"],
        };

        if (! $validationResult['valid']) {
            return HealthCheckResult::failed(
                $this->name(),
                $validationResult['message'],
                ['driver' => $driver]
            );
        }

        $operationsTest = $this->testCacheOperations();

        if (! $operationsTest['success']) {
            return HealthCheckResult::failed(
                $this->name(),
                'Cache operations failed: '.$operationsTest['message'],
                ['driver' => $driver]
            );
        }

        return HealthCheckResult::healthy(
            $this->name(),
            'Cache operational',
            [
                'driver' => $driver,
                'status' => $validationResult['message'],
                'operations' => 'Read/Write successful',
            ]
        );
    }

    /**
     * Test cache read/write operations.
     *
     * @return array{success: bool, message: string}
     */
    private function testCacheOperations(): array
    {
        try {
            $key = 'health-check-'.time();
            $value = 'test-value';

            Cache::put($key, $value, 60);
            $retrieved = Cache::get($key);
            Cache::forget($key);

            if ($retrieved !== $value) {
                return ['success' => false, 'message' => 'Cache value mismatch'];
            }

            return ['success' => true, 'message' => 'Operations successful'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Validate Redis cache driver.
     *
     * @return array{valid: bool, message: string}
     */
    private function validateRedisCache(): array
    {
        try {
            Redis::connection()->ping();

            return ['valid' => true, 'message' => 'Redis cache connection established'];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'Redis connection failed: '.$e->getMessage()];
        }
    }

    /**
     * Validate database cache driver.
     *
     * @return array{valid: bool, message: string}
     */
    private function validateDatabaseCache(): array
    {
        try {
            $tableExists = DB::getSchemaBuilder()->hasTable('cache');

            if (! $tableExists) {
                return [
                    'valid' => false,
                    'message' => 'Cache table not found (run: php artisan cache:table && php artisan migrate)',
                ];
            }

            return ['valid' => true, 'message' => 'Database cache table exists'];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'Database cache check failed: '.$e->getMessage()];
        }
    }

    /**
     * Validate file cache driver.
     *
     * @return array{valid: bool, message: string}
     */
    private function validateFileCache(): array
    {
        $path = storage_path('framework/cache/data');

        if (! is_dir($path)) {
            return ['valid' => false, 'message' => "Cache directory not found: {$path}"];
        }

        if (! is_writable($path)) {
            return ['valid' => false, 'message' => "Cache directory not writable: {$path}"];
        }

        return ['valid' => true, 'message' => 'File cache directory writable'];
    }

    /**
     * Validate Memcached cache driver.
     *
     * @return array{valid: bool, message: string}
     */
    private function validateMemcachedCache(): array
    {
        try {
            $testKey = 'memcached-health-'.time();
            Cache::put($testKey, 'test', 1);
            Cache::forget($testKey);

            return ['valid' => true, 'message' => 'Memcached connection established'];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'Memcached connection failed: '.$e->getMessage()];
        }
    }
}
