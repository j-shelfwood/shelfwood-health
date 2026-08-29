<?php

namespace Shelfwood\Health\Checks;

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;

/**
 * Sentry error tracking health check with DSN validation.
 *
 * Validates Sentry configuration for error monitoring and performance tracking.
 * Critical for production error visibility and debugging.
 *
 * Check outcomes:
 * - Failed: DSN missing in production or invalid format
 * - Warning: Sentry disabled in non-production
 * - Healthy: Sentry properly configured
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/
 * @see https://forum.sentry.io/t/validating-sentry-io-credentials-integration-health-check/1457
 * @see https://github.com/getsentry/sentry-laravel
 */
class SentryHealthCheck extends HealthCheck
{
    /**
     * Create Sentry health check.
     *
     * @param  string|null  $sentryDsn  Sentry DSN
     * @param  string|null  $sentryEnvironment  Sentry environment name
     * @param  string|null  $environment  Application environment
     */
    public function __construct(
        private ?string $sentryDsn = null,
        private ?string $sentryEnvironment = null,
        private ?string $environment = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return '🐛 Sentry';
    }

    /**
     * Perform Sentry health check.
     *
     * Validation flow:
     * 1. Check if DSN configured (required in production)
     * 2. Validate DSN format
     * 3. Verify environment configuration
     *
     * Note: We don't send test events to avoid polluting Sentry data.
     * Use `php artisan sentry:test` for actual connectivity testing.
     *
     * @return HealthCheckResult Status with Sentry configuration
     */
    #[\Override]
    public function check(): HealthCheckResult
    {
        if (! $this->sentryDsn && in_array($this->environment, ['production', 'staging'])) {
            return HealthCheckResult::failed(
                $this->name(),
                'Sentry DSN not configured in production (SENTRY_DSN or SENTRY_LARAVEL_DSN)',
                ['hint' => 'Error tracking disabled - production errors will not be logged']
            );
        }

        if (! $this->sentryDsn) {
            return HealthCheckResult::healthy(
                $this->name(),
                'Sentry disabled in development environment',
                ['environment' => $this->environment]
            );
        }

        if (! $this->isValidDsnFormat($this->sentryDsn)) {
            return HealthCheckResult::failed(
                $this->name(),
                'Invalid Sentry DSN format (must be https://<key>@<organization>.ingest.sentry.io/<project>)',
                ['dsn' => substr($this->sentryDsn, 0, 30).'...']
            );
        }

        $projectId = $this->extractProjectId($this->sentryDsn);

        return HealthCheckResult::healthy(
            $this->name(),
            'Sentry configured for error tracking',
            [
                'project_id' => $projectId,
                'environment' => $this->sentryEnvironment ?? $this->environment,
                'dsn_configured' => 'Yes',
                'hint' => 'Run "php artisan sentry:test" to test connectivity',
            ]
        );
    }

    /**
     * Validate Sentry DSN format.
     *
     * Supported DSN formats:
     * - https://<public_key>@<organization>.ingest.sentry.io/<project_id> (Sentry SaaS)
     * - https://<public_key>@sentry.io/<project_id> (older Sentry SaaS format)
     * - https://<public_key>@<custom-domain>/<project_id> (self-hosted Sentry)
     * - https://<public_key>@<custom-domain>:port/<project_id> (self-hosted with custom port)
     *
     * @param  string  $dsn  Sentry DSN
     * @return bool True if DSN format is valid
     */
    private function isValidDsnFormat(string $dsn): bool
    {
        // Sentry SaaS formats
        if (preg_match('#^https?://[a-zA-Z0-9]+@[^/]+\.ingest\.sentry\.io/\d+$#i', $dsn)) {
            return true;
        }
        if (preg_match('#^https?://[a-zA-Z0-9]+@sentry\.io/\d+$#i', $dsn)) {
            return true;
        }
        // Self-hosted Sentry: https://<key>@<domain>/<project_id> or https://<key>@<domain>:port/<project_id>
        if (preg_match('#^https?://[a-zA-Z0-9]+@[a-zA-Z0-9.-]+(:\d+)?/\d+$#i', $dsn)) {
            return true;
        }

        return false;
    }

    /**
     * Extract project ID from Sentry DSN.
     *
     * @param  string  $dsn  Sentry DSN
     * @return string|null Project ID or null if not found
     */
    private function extractProjectId(string $dsn): ?string
    {
        if (preg_match('#/(\d+)$#', $dsn, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
