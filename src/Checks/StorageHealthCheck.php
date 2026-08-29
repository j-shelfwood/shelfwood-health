<?php

namespace Shelfwood\Health\Checks;

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Storage and filesystem health check with disk space monitoring.
 *
 * Validates storage disks, disk space availability, write permissions,
 * and symbolic links. Critical for property images, uploads, and backups.
 *
 * Check outcomes:
 * - Failed: Disk unwritable, space critically low, or S3 connectivity failed
 * - Warning: Disk space below warning threshold
 * - Healthy: All storage systems operational with adequate space
 *
 * @see https://spatie.be/docs/laravel-health/v1/available-checks/used-disk-space
 * @see https://github.com/spatie/laravel-health Laravel Health package
 * @see https://laravel.com/docs/11.x/filesystem
 */
class StorageHealthCheck extends HealthCheck
{
    /**
     * Create storage health check.
     *
     * @param  array<string>  $disksToCheck  Disk names to validate
     * @param  int  $warningThresholdPercentage  Disk usage warning threshold
     * @param  int  $errorThresholdPercentage  Disk usage error threshold
     * @param  string|null  $environment  Application environment
     */
    public function __construct(
        private array $disksToCheck = ['local', 'public', 'images'],
        private int $warningThresholdPercentage = 80,
        private int $errorThresholdPercentage = 90,
        private ?string $environment = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return '💾 Storage';
    }

    /**
     * Perform storage health check.
     *
     * Validation flow:
     * 1. Check each configured disk for writability
     * 2. Monitor disk space usage (local disks only)
     * 3. Verify S3 connectivity (if S3 disk configured)
     * 4. Validate symbolic links exist
     * 5. Report aggregated status
     *
     * @return HealthCheckResult Status with storage metrics
     */
    #[\Override]
    public function check(): HealthCheckResult
    {
        $diskStatuses = [];
        $hasErrors = false;
        $hasWarnings = false;

        foreach ($this->disksToCheck as $diskName) {
            $diskStatus = $this->checkDisk($diskName);
            $diskStatuses[$diskName] = $diskStatus;

            if ($diskStatus['status'] === 'failed') {
                $hasErrors = true;
            } elseif ($diskStatus['status'] === 'warning') {
                $hasWarnings = true;
            }
        }

        $symlinkStatus = $this->checkStorageSymlink();
        if (! $symlinkStatus['exists']) {
            $hasWarnings = true;
            $diskStatuses['symlink'] = $symlinkStatus;
        }

        if ($hasErrors) {
            return HealthCheckResult::failed(
                $this->name(),
                'One or more storage disks have critical issues',
                $diskStatuses
            );
        }

        if ($hasWarnings) {
            return HealthCheckResult::warning(
                $this->name(),
                'Storage system has warnings',
                $diskStatuses
            );
        }

        return HealthCheckResult::healthy(
            $this->name(),
            count($this->disksToCheck).' disk(s) operational',
            $diskStatuses
        );
    }

    /**
     * Check individual disk health.
     *
     * @param  string  $diskName  Disk name to check
     * @return array{status: string, message: string, usage_percent?: float, free_space_gb?: float}
     */
    private function checkDisk(string $diskName): array
    {
        try {
            $disk = Storage::disk($diskName);

            if (! $this->isDiskWritable($disk)) {
                return [
                    'status' => 'failed',
                    'message' => 'Disk not writable',
                ];
            }

            $config = config("filesystems.disks.{$diskName}");

            if (isset($config['driver']) && $config['driver'] === 'local' && isset($config['root'])) {
                $path = $config['root'];

                if (is_dir($path)) {
                    $diskUsage = $this->getDiskUsage($path);

                    if ($diskUsage['usage_percent'] >= $this->errorThresholdPercentage) {
                        return [
                            'status' => 'failed',
                            'message' => "Disk usage {$diskUsage['usage_percent']}% exceeds error threshold",
                            'usage_percent' => $diskUsage['usage_percent'],
                            'free_space_gb' => $diskUsage['free_space_gb'],
                        ];
                    }

                    if ($diskUsage['usage_percent'] >= $this->warningThresholdPercentage) {
                        return [
                            'status' => 'warning',
                            'message' => "Disk usage {$diskUsage['usage_percent']}% approaching threshold",
                            'usage_percent' => $diskUsage['usage_percent'],
                            'free_space_gb' => $diskUsage['free_space_gb'],
                        ];
                    }

                    return [
                        'status' => 'healthy',
                        'message' => 'Operational',
                        'usage_percent' => $diskUsage['usage_percent'],
                        'free_space_gb' => $diskUsage['free_space_gb'],
                    ];
                }
            }

            if (isset($config['driver']) && $config['driver'] === 's3') {
                return [
                    'status' => 'healthy',
                    'message' => 'S3 connectivity verified',
                ];
            }

            return [
                'status' => 'healthy',
                'message' => 'Operational',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => 'Disk check failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Test if disk is writable by creating and deleting a test file.
     *
     * @param  Filesystem  $disk  Disk instance
     * @return bool True if disk is writable
     */
    private function isDiskWritable($disk): bool
    {
        try {
            $testFile = '.health-check-'.getmypid().'-'.bin2hex(random_bytes(4));
            $disk->put($testFile, 'test');
            $exists = $disk->exists($testFile);
            $disk->delete($testFile);

            return $exists;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get disk usage statistics.
     *
     * @param  string  $path  Filesystem path
     * @return array{usage_percent: float, free_space_gb: float, total_space_gb: float}
     */
    private function getDiskUsage(string $path): array
    {
        $freeSpace = disk_free_space($path);
        $totalSpace = disk_total_space($path);

        $usedSpace = $totalSpace - $freeSpace;
        $usagePercent = ($usedSpace / $totalSpace) * 100;

        return [
            'usage_percent' => round($usagePercent, 2),
            'free_space_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
            'total_space_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
        ];
    }

    /**
     * Check if storage symlink exists.
     *
     * @return array{exists: bool, message: string}
     */
    private function checkStorageSymlink(): array
    {
        $publicStoragePath = public_path('storage');

        if (is_link($publicStoragePath)) {
            return [
                'exists' => true,
                'message' => 'Storage symlink exists',
            ];
        }

        return [
            'exists' => false,
            'message' => 'Storage symlink missing (run: php artisan storage:link)',
        ];
    }
}
