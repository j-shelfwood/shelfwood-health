<?php

namespace Shelfwood\Health\Checks;

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Session storage health check with multi-driver validation.
 *
 * Validates session driver configuration and storage backend health.
 * Critical for user authentication, shopping cart, and booking flow persistence.
 *
 * Check outcomes:
 * - Failed: Session storage inaccessible or unconfigured
 * - Warning: File driver in production with load balancing
 * - Healthy: Session storage operational
 *
 * @see https://laravel.com/docs/11.x/session
 * @see https://aregsar.com/blog/2020/configure-laravel-session-to-use-redis/
 */
class SessionHealthCheck extends HealthCheck
{
    /**
     * Create session health check.
     *
     * @param  string|null  $sessionDriver  Session driver name
     * @param  string|null  $sessionConnection  Database/Redis connection for sessions
     * @param  string|null  $environment  Application environment
     */
    public function __construct(
        private ?string $sessionDriver = null,
        private ?string $sessionConnection = null,
        private ?string $environment = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return '🔐 Session';
    }

    /**
     * Perform session health check.
     *
     * Validation flow:
     * 1. Validate session driver configured
     * 2. Check driver-specific storage health
     * 3. Warn if file driver in production (load balancing concern)
     *
     * @return HealthCheckResult Status with session configuration
     */
    #[\Override]
    public function check(): HealthCheckResult
    {
        $driver = $this->sessionDriver ?? config('session.driver', 'file');

        if ($driver === 'file' && in_array($this->environment, ['production', 'staging'])) {
            return HealthCheckResult::warning(
                $this->name(),
                'File session driver not recommended for production load-balanced environments',
                [
                    'driver' => $driver,
                    'hint' => 'Consider using database or redis driver for distributed systems',
                ]
            );
        }

        $validationResult = match ($driver) {
            'database' => $this->validateDatabaseDriver(),
            'redis' => $this->validateRedisDriver(),
            'file' => $this->validateFileDriver(),
            'cookie' => ['valid' => true, 'message' => 'Cookie driver (client-side storage)'],
            'array' => ['valid' => true, 'message' => 'Array driver (testing only)'],
            default => ['valid' => false, 'message' => "Unknown session driver: {$driver}"],
        };

        if (! $validationResult['valid']) {
            return HealthCheckResult::failed(
                $this->name(),
                $validationResult['message'],
                ['driver' => $driver]
            );
        }

        return HealthCheckResult::healthy(
            $this->name(),
            'Session storage operational',
            [
                'driver' => $driver,
                'status' => $validationResult['message'],
            ]
        );
    }

    /**
     * Validate database session driver.
     *
     * @return array{valid: bool, message: string}
     */
    private function validateDatabaseDriver(): array
    {
        try {
            $connection = $this->sessionConnection ?? config('database.default');
            $tableExists = DB::connection($connection)
                ->getSchemaBuilder()
                ->hasTable('sessions');

            if (! $tableExists) {
                return [
                    'valid' => false,
                    'message' => 'Sessions table not found (run: php artisan session:table && php artisan migrate)',
                ];
            }

            return ['valid' => true, 'message' => "Database sessions ({$connection})"];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'Database session check failed: '.$e->getMessage()];
        }
    }

    /**
     * Validate Redis session driver.
     *
     * @return array{valid: bool, message: string}
     */
    private function validateRedisDriver(): array
    {
        try {
            $connection = $this->sessionConnection ?? 'default';
            Redis::connection($connection)->ping();

            return ['valid' => true, 'message' => "Redis sessions ({$connection})"];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'Redis session connection failed: '.$e->getMessage()];
        }
    }

    /**
     * Validate file session driver.
     *
     * @return array{valid: bool, message: string}
     */
    private function validateFileDriver(): array
    {
        $path = storage_path('framework/sessions');

        if (! is_dir($path)) {
            return ['valid' => false, 'message' => "Session directory not found: {$path}"];
        }

        if (! is_writable($path)) {
            return ['valid' => false, 'message' => "Session directory not writable: {$path}"];
        }

        return ['valid' => true, 'message' => 'File sessions (local storage)'];
    }
}
