<?php

namespace Shelfwood\Health\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Shelfwood\Health\Contracts\InstanceIdentifier;
use Shelfwood\Health\HealthCheckResult;
use Shelfwood\Health\HealthCheckStatus;

/**
 * Health API endpoint for infrastructure monitoring.
 *
 * RESPONSE CONTRACT - consumed by external monitoring, which keys off
 * `checks[].key`. Adding fields is safe; renaming or removing them is a
 * breaking change for every consumer. See SPEC.md.
 *
 * {
 *   "status": "healthy|warning|failed",
 *   "instance": "example.com",
 *   "environment": "production",
 *   "timestamp": "2026-01-12T18:00:00+00:00",
 *   "response_time_ms": 12.34,
 *   "checks": [
 *     {"key": "database", "name": "Database", "raw_name": "📝 Database",
 *      "status": "healthy", "message": "Connected", "meta": null}
 *   ],
 *   "summary": {"total": 10, "healthy": 9, "warning": 1, "failed": 0},
 *   "metrics": {"tenants": 12, "uploads_7d": 340},          // only when providers configured
 *   "metrics_errors": ["App\Health\KpiMetrics: <reason>"] // only when a provider threw
 * }
 *
 * `metrics` carries statuses-free business numbers from health.metrics
 * providers (Contracts\MetricsProvider). They never affect `status` or the
 * HTTP code.
 *
 * HTTP status is 200 for healthy AND warning (a warning is still "up"), and
 * 503 for failed. Monitors distinguish degraded from down by the body, not
 * the status code.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $startTime = microtime(true);

        $results = $this->runHealthChecks();

        $summary = [
            'total' => $results->count(),
            'healthy' => $results->filter(fn (HealthCheckResult $r) => $r->status === HealthCheckStatus::Healthy)->count(),
            'warning' => $results->filter(fn (HealthCheckResult $r) => $r->status === HealthCheckStatus::Warning)->count(),
            'failed' => $results->filter(fn (HealthCheckResult $r) => $r->status === HealthCheckStatus::Failed)->count(),
        ];

        $overallStatus = $this->determineOverallStatus($summary);

        [$metrics, $metricsErrors] = $this->collectMetrics();

        $response = [
            'status' => $overallStatus,
            'instance' => App::make(InstanceIdentifier::class)->id(),
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'checks' => $results->map(fn (HealthCheckResult $result) => [
                'key' => $result->key,
                'name' => $this->cleanCheckName($result->name),
                'raw_name' => $result->name,
                'status' => $result->status->value,
                'message' => $result->message,
                'meta' => $result->meta,
            ])->values()->toArray(),
            'summary' => $summary,
        ];

        if ($metrics !== []) {
            $response['metrics'] = $metrics;
        }
        if ($metricsErrors !== []) {
            $response['metrics_errors'] = $metricsErrors;
        }

        $httpStatus = match ($overallStatus) {
            'healthy' => 200,
            'warning' => 200,  // Warnings are still "up"
            'failed' => 503,   // Service unavailable
            default => 200,
        };

        return response()->json($response, $httpStatus);
    }

    /**
     * Run every registered check.
     *
     * A check that throws must not take the endpoint down - the monitor needs
     * a response even when one dependency is broken. The exception becomes a
     * failed result with the detail in `meta`.
     *
     * @return Collection<int, HealthCheckResult>
     */
    protected function runHealthChecks(): Collection
    {
        return collect(config('health.checks', []))
            ->map(function (string $class) {
                try {
                    return App::make($class)->run();
                } catch (\Throwable $e) {
                    $name = class_basename($class);
                    $label = preg_replace('/HealthCheck$/', '', $name) ?? $name;

                    return HealthCheckResult::failed(
                        $label,
                        'Health check could not be executed',
                        [
                            'error' => $e->getMessage(),
                            'exception' => $e::class,
                            'check_class' => $class,
                        ],
                        $this->buildKeyFromClass($class)
                    );
                }
            });
    }

    /**
     * Run every configured metrics provider. A provider that throws must not
     * take the endpoint down; its failure is listed in metrics_errors.
     *
     * @return array{0: array<string, int|float|string>, 1: list<string>}
     */
    protected function collectMetrics(): array
    {
        $metrics = [];
        $errors = [];
        foreach (config('health.metrics', []) as $class) {
            try {
                $metrics = array_merge($metrics, App::make($class)->metrics());
            } catch (\Throwable $e) {
                $errors[] = $class.': '.$e->getMessage();
            }
        }

        return [$metrics, $errors];
    }

    protected function determineOverallStatus(array $summary): string
    {
        if ($summary['failed'] > 0) {
            return 'failed';
        }

        if ($summary['warning'] > 0) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Strip emoji/symbol prefixes: "📝 Database Write" -> "Database Write".
     * `raw_name` preserves the original.
     */
    protected function cleanCheckName(string $name): string
    {
        return trim(preg_replace('/^[^\\p{L}\\p{N}]+/u', '', $name));
    }

    protected function buildKeyFromClass(string $class): string
    {
        $base = class_basename($class);
        $base = preg_replace('/HealthCheck$/', '', $base) ?? $base;
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $base));

        return $kebab ?: strtolower($base);
    }
}
