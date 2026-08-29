<?php

namespace Shelfwood\Health\Checks;

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;
use Illuminate\Support\Facades\DB;

/**
 * Database write capability health check.
 *
 * Validates that the database is writable, not just connectable.
 * Critical for SQLite where read-only permission issues can occur
 * when WAL/SHM files have incorrect ownership (forge:forge instead of forge:www-data).
 *
 * Check outcomes:
 * - Failed: Cannot acquire write lock (readonly database)
 * - Failed: Cannot write to database (permission denied)
 * - Warning: SQLite WAL files have incorrect permissions
 * - Healthy: Database is fully writable
 *
 * @see https://www.sqlite.org/wal.html SQLite Write-Ahead Logging
 */
class DatabaseWriteHealthCheck extends HealthCheck
{
    public function __construct(
        private ?string $defaultConnection = null,
        private ?string $databasePath = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return '📝 Database Write';
    }

    /**
     * Perform database write capability check.
     *
     * Validation flow:
     * 1. Test write lock acquisition (BEGIN IMMEDIATE)
     * 2. For SQLite: Check WAL/SHM file permissions
     * 3. Rollback to avoid any data changes
     *
     * @return HealthCheckResult Status with write capability details
     */
    #[\Override]
    public function check(): HealthCheckResult
    {
        $connection = $this->defaultConnection ?? config('database.default', 'sqlite');
        $meta = ['driver' => $connection];

        // Test 1: Write lock acquisition (critical test)
        try {
            // BEGIN IMMEDIATE acquires a write lock immediately
            // This fails if database is readonly or locked
            DB::statement('BEGIN IMMEDIATE');
            DB::statement('ROLLBACK');
        } catch (\Exception $e) {
            $message = $e->getMessage();

            // Detect specific SQLite readonly error
            if (str_contains($message, 'readonly') || str_contains($message, 'read-only')) {
                return HealthCheckResult::failed(
                    $this->name(),
                    'Database is read-only (check file permissions: chown forge:www-data)',
                    array_merge($meta, [
                        'error' => $message,
                        'hint' => 'Run: chown forge:www-data database/database.sqlite*',
                    ])
                );
            }

            // Detect lock timeout
            if (str_contains($message, 'locked') || str_contains($message, 'busy')) {
                return HealthCheckResult::failed(
                    $this->name(),
                    'Database is locked (possible long-running transaction)',
                    array_merge($meta, ['error' => $message])
                );
            }

            return HealthCheckResult::failed(
                $this->name(),
                "Write test failed: {$message}",
                $meta
            );
        }

        // Test 2: SQLite-specific permission check
        if ($connection === 'sqlite' && $this->databasePath) {
            $permissionResult = $this->checkSqlitePermissions();
            if ($permissionResult !== null) {
                return $permissionResult;
            }
        }

        return HealthCheckResult::healthy(
            $this->name(),
            'Database is writable',
            $meta
        );
    }

    /**
     * Check SQLite file permissions for potential issues.
     *
     * @return HealthCheckResult|null Warning/failed result if issues found, null if OK
     */
    private function checkSqlitePermissions(): ?HealthCheckResult
    {
        $dbPath = $this->databasePath;
        $walPath = $dbPath.'-wal';
        $shmPath = $dbPath.'-shm';
        $dbDir = dirname($dbPath);

        $issues = [];
        $meta = [
            'database_path' => $dbPath,
        ];

        // Check main database file
        if (file_exists($dbPath)) {
            $dbGroup = $this->getFileGroup($dbPath);
            $meta['database_group'] = $dbGroup;
            if ($dbGroup !== 'www-data') {
                $issues[] = "database.sqlite group is '{$dbGroup}' (should be www-data)";
            }
        }

        // Check WAL file if exists
        if (file_exists($walPath)) {
            $walGroup = $this->getFileGroup($walPath);
            $walSize = filesize($walPath);
            $meta['wal_group'] = $walGroup;
            $meta['wal_size_kb'] = round($walSize / 1024, 2);

            if ($walGroup !== 'www-data') {
                $issues[] = "database.sqlite-wal group is '{$walGroup}' (should be www-data)";
            }

            // Warn if WAL file is large (checkpoint may not be running)
            if ($walSize > 10 * 1024 * 1024) { // 10MB
                $issues[] = "WAL file is large ({$meta['wal_size_kb']}KB) - checkpoint may not be running";
            }
        }

        // Check SHM file if exists
        if (file_exists($shmPath)) {
            $shmGroup = $this->getFileGroup($shmPath);
            $meta['shm_group'] = $shmGroup;

            if ($shmGroup !== 'www-data') {
                $issues[] = "database.sqlite-shm group is '{$shmGroup}' (should be www-data)";
            }
        }

        // Check directory setgid
        if (is_dir($dbDir)) {
            $dirPerms = fileperms($dbDir);
            $hasSetgid = ($dirPerms & 0x400) !== 0; // Check setgid bit
            $dirGroup = $this->getFileGroup($dbDir);

            $meta['directory_group'] = $dirGroup;
            $meta['directory_setgid'] = $hasSetgid;

            if (! $hasSetgid) {
                $issues[] = "Database directory missing setgid bit (new files won't inherit www-data group)";
            }
            if ($dirGroup !== 'www-data') {
                $issues[] = "Database directory group is '{$dirGroup}' (should be www-data)";
            }
        }

        if (! empty($issues)) {
            return HealthCheckResult::warning(
                $this->name(),
                'Permission issues detected: '.implode('; ', array_slice($issues, 0, 2)),
                array_merge($meta, ['issues' => $issues])
            );
        }

        return null;
    }

    /**
     * Get group name of a file.
     *
     * @param  string  $path  File path
     * @return string Group name or 'unknown'
     */
    private function getFileGroup(string $path): string
    {
        if (! file_exists($path)) {
            return 'unknown';
        }

        $stat = @stat($path);
        if ($stat === false) {
            return 'unknown';
        }

        $groupInfo = @posix_getgrgid($stat['gid']);

        return $groupInfo['name'] ?? 'unknown';
    }
}
