<?php

namespace Shelfwood\Health\Checks;

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Metrics cache health check.
 *
 * Validates the dedicated metrics cache store that persists across deployments.
 * This store uses Redis DB 2 and is NOT cleared by cache:clear or optimize:clear.
 *
 * Used by:
 * - AnalyticsActivityTracker (funnel metrics)
 * - PmsActivityTracker (PMS sync activity)
 * - ScheduledJobActivityTracker (job monitoring)
 * - RecordQueueJobActivity (queue health)
 *
 * Check outcomes:
 * - Failed: Metrics cache read/write operations failed
 * - Warning: Array driver in use (no persistence, typically testing mode)
 * - Healthy: Metrics cache operational with key count
 *
 * @see config/cache.php for metrics store configuration
 * @see config/database.php for Redis DB allocation
 */
class MetricsCacheHealthCheck extends HealthCheck
{
    public function __construct(
        private ?string $environment = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return '📈 Metrics Cache';
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        $storeConfig = config('cache.stores.metrics');
        $driver = $storeConfig['driver'] ?? 'unknown';

        // Warning for array driver in production (no persistence)
        if ($driver === 'array' && $this->environment === 'production') {
            return HealthCheckResult::warning(
                $this->name(),
                'Metrics cache using array driver (no persistence)',
                [
                    'driver' => $driver,
                    'hint' => 'Configure CACHE_DRIVER=redis for production metrics persistence',
                ]
            );
        }

        // Test read/write operations
        // One transient latency blip must not fail the whole health document
        // (observed 2026-08-31: staging redis answered slow for ~40 min and
        // every monitor sample during it paged as "failed"). Retry once.
        $operationsTest = $this->testCacheOperations();
        if (! $operationsTest['success']) {
            usleep(250_000);
            $operationsTest = $this->testCacheOperations();
        }

        if (! $operationsTest['success']) {
            return HealthCheckResult::failed(
                $this->name(),
                'Metrics cache operations failed: '.$operationsTest['message'],
                ['driver' => $driver]
            );
        }

        // Get key count for visibility (Redis only)
        $keyCount = $this->getKeyCount($driver);
        $metricsInfo = $this->getMetricsInfo();

        return HealthCheckResult::healthy(
            $this->name(),
            $this->buildHealthyMessage($driver, $keyCount),
            [
                'driver' => $driver,
                'connection' => $storeConfig['connection'] ?? 'default',
                'key_count' => $keyCount,
                'operations' => 'Read/Write successful',
                ...$metricsInfo,
            ]
        );
    }

    /**
     * Test metrics cache read/write operations.
     *
     * @return array{success: bool, message: string}
     */
    private function testCacheOperations(): array
    {
        try {
            $key = 'health:metrics_cache_test:'.bin2hex(random_bytes(8));
            $value = 'test-value-'.uniqid();

            Cache::store('metrics')->put($key, $value, 60);
            $retrieved = Cache::store('metrics')->get($key);
            Cache::store('metrics')->forget($key);

            if ($retrieved !== $value) {
                return ['success' => false, 'message' => 'Cache value mismatch'];
            }

            return ['success' => true, 'message' => 'Operations successful'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get number of keys in metrics cache.
     */
    private function getKeyCount(string $driver): ?int
    {
        if ($driver !== 'redis') {
            return null;
        }

        try {
            return Redis::connection('metrics')->dbsize();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get high-level metrics info for visibility.
     *
     * @return array<string, mixed>
     */
    private function getMetricsInfo(): array
    {
        try {
            // Check for presence of key metric categories
            $hasAnalytics = Cache::store('metrics')->has('analytics:activity:'.config('instance.default').':last:search_performed')
                || $this->hasKeysMatching('analytics:');
            $hasPms = $this->hasKeysMatching('pms:activity:');
            $hasScheduledJobs = Cache::store('metrics')->has('health:scheduled_jobs:index');
            $hasQueueHealth = $this->hasKeysMatching('health:queue:');

            return [
                'has_analytics' => $hasAnalytics,
                'has_pms_activity' => $hasPms,
                'has_scheduled_jobs' => $hasScheduledJobs,
                'has_queue_health' => $hasQueueHealth,
            ];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Check if any keys match a pattern (Redis only).
     */
    private function hasKeysMatching(string $pattern): bool
    {
        try {
            $keys = Redis::connection('metrics')->keys('*'.$pattern.'*');

            return count($keys) > 0;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Build descriptive message for healthy status.
     */
    private function buildHealthyMessage(string $driver, ?int $keyCount): string
    {
        if ($driver === 'array') {
            return 'Metrics cache operational (array driver, testing mode)';
        }

        if ($keyCount !== null) {
            return "Metrics cache operational ({$keyCount} keys in Redis DB 2)";
        }

        return 'Metrics cache operational';
    }
}
