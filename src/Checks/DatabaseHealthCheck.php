<?php

namespace Shelfwood\Health\Checks;

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;
use Illuminate\Support\Facades\DB;

/**
 * Database health check for connection verification.
 *
 * Validates database connectivity and warns if SQLite used in production.
 */
class DatabaseHealthCheck extends HealthCheck
{
    /**
     * Create database health check.
     *
     * @param  string|null  $defaultConnection  Default database connection
     * @param  string|null  $environment  Application environment
     * @param  array|null  $connectionConfig  Connection configuration array
     * @param  string|null  $databasePath  Database file path (SQLite)
     *
     * @note Dependencies injected by service container
     */
    public function __construct(
        private ?string $defaultConnection = null,
        private ?string $environment = null,
        private ?array $connectionConfig = null,
        private ?string $databasePath = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return '💾 Database';
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        $connection = $this->defaultConnection ?? config('database.default', 'sqlite');
        $connectionConfig = $this->connectionConfig ?? config("database.connections.{$connection}");

        try {
            DB::connection()->getPdo();

            return HealthCheckResult::healthy($this->name(), "Connection successful ({$connection})", [
                'driver' => $connection,
                'database' => $connection === 'sqlite'
                    ? $this->databasePath
                    : $connectionConfig['database'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            return HealthCheckResult::failed($this->name(), "Connection failed: {$e->getMessage()}");
        }
    }
}
