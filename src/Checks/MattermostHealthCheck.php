<?php

namespace Shelfwood\Health\Checks;

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;
use Illuminate\Support\Facades\Http;

/**
 * Mattermost webhook health check with URL validation.
 *
 * Check outcomes:
 * - Failed: Missing webhook in production/staging OR invalid URL format OR connectivity test failed
 * - Healthy: Valid Mattermost webhook URL configured (and connectivity test passed if enabled)
 */
class MattermostHealthCheck extends HealthCheck
{
    /**
     * Create Mattermost health check.
     *
     * @param  string|null  $environment  Application environment
     * @param  string|null  $mattermostWebhookUrl  Mattermost webhook URL
     *
     * @note Dependencies injected by service container
     */
    public function __construct(
        private ?string $environment = null,
        private ?string $mattermostWebhookUrl = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'Mattermost';
    }

    /**
     * Perform Mattermost webhook health check.
     *
     * Validation flow:
     * 1. Check if URL exists (required in production/staging)
     * 2. Validate URL format (must be valid HTTPS URL with /hooks/ path)
     * 3. Optional: Test webhook connectivity (if enabled in config)
     *
     * @return HealthCheckResult Status with webhook validation details
     */
    #[\Override]
    public function check(): HealthCheckResult
    {
        // Webhook required in production/staging
        if (! $this->mattermostWebhookUrl && in_array($this->environment, ['production', 'staging'])) {
            return HealthCheckResult::failed($this->name(), 'Missing Mattermost webhook URL (MATTERMOST_WEBHOOK_URL)');
        }

        // Webhook optional in development
        if (! $this->mattermostWebhookUrl) {
            return HealthCheckResult::healthy($this->name(), 'Mattermost disabled in non-production environment');
        }

        // Validate URL format (must contain /hooks/)
        if (! str_contains($this->mattermostWebhookUrl, '/hooks/')) {
            return HealthCheckResult::failed(
                $this->name(),
                'Invalid Mattermost webhook URL format (must contain /hooks/ path)',
                ['url' => substr($this->mattermostWebhookUrl, 0, 50).'...']
            );
        }

        // Validate HTTPS
        if (! str_starts_with($this->mattermostWebhookUrl, 'https://')) {
            return HealthCheckResult::failed(
                $this->name(),
                'Mattermost webhook URL must use HTTPS',
                ['url' => substr($this->mattermostWebhookUrl, 0, 50).'...']
            );
        }

        // Optional: Test connectivity with a minimal payload
        if ($this->shouldTestConnectivity()) {
            try {
                // Mattermost webhooks return 200 OK on valid POST (POST-based verification)
                // We test with an empty text which returns 400 (bad request) but confirms connectivity
                $response = Http::timeout(5)->post($this->mattermostWebhookUrl, ['text' => '']);

                // 400 means the webhook exists but needs valid payload - this is expected
                // 404 means webhook doesn't exist
                // 403 means forbidden (wrong token)
                if ($response->status() === 404) {
                    return HealthCheckResult::failed(
                        $this->name(),
                        'Mattermost webhook not found (404)'
                    );
                }

                if ($response->status() === 403) {
                    return HealthCheckResult::failed(
                        $this->name(),
                        'Mattermost webhook forbidden (invalid token)'
                    );
                }

            } catch (\Exception $e) {
                return HealthCheckResult::failed(
                    $this->name(),
                    'Mattermost webhook connectivity test failed: '.$e->getMessage()
                );
            }
        }

        return HealthCheckResult::healthy($this->name(), 'Webhook URL validated');
    }

    /**
     * Determine if connectivity testing is enabled.
     *
     * @return bool True to test actual webhook reachability
     */
    private function shouldTestConnectivity(): bool
    {
        return config('health.test_mattermost_connectivity', false);
    }
}
