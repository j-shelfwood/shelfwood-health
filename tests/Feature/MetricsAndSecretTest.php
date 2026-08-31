<?php

use Shelfwood\Health\Contracts\MetricsProvider;
use Shelfwood\Health\Http\Middleware\VerifyHealthSecret;

class TenantsMetrics implements MetricsProvider
{
    public function metrics(): array
    {
        return ['tenants' => 12, 'uploads_7d' => 340];
    }
}

class BrokenMetrics implements MetricsProvider
{
    public function metrics(): array
    {
        throw new RuntimeException('db gone');
    }
}

it('adds a metrics block without affecting status', function () {
    config()->set('health.checks', [PassingCheck::class]);
    config()->set('health.metrics', [TenantsMetrics::class]);

    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('status', 'healthy')
        ->assertJsonPath('metrics.tenants', 12)
        ->assertJsonPath('metrics.uploads_7d', 340)
        ->assertJsonMissingPath('metrics_errors');
});

it('a throwing provider lands in metrics_errors, never breaks the endpoint', function () {
    config()->set('health.checks', [PassingCheck::class]);
    config()->set('health.metrics', [BrokenMetrics::class, TenantsMetrics::class]);

    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('status', 'healthy')
        ->assertJsonPath('metrics.tenants', 12);
    expect($response->json('metrics_errors.0'))->toContain('BrokenMetrics');
});

it('omits the metrics block entirely when no providers are configured', function () {
    config()->set('health.checks', [PassingCheck::class]);
    config()->set('health.metrics', []);

    $this->getJson('/api/v1/health')->assertOk()->assertJsonMissingPath('metrics');
});

describe('VerifyHealthSecret', function () {
    beforeEach(function () {
        config()->set('health.checks', [PassingCheck::class]);
        Route::get('/gated/health', [\Shelfwood\Health\Http\Controllers\HealthController::class, '__invoke'])
            ->middleware(VerifyHealthSecret::class);
    });

    it('rejects without the header', function () {
        config()->set('health.route.secret', 's3cret');
        $this->getJson('/gated/health')->assertStatus(401);
    });

    it('rejects a wrong secret', function () {
        config()->set('health.route.secret', 's3cret');
        $this->getJson('/gated/health', ['x-cron-secret' => 'nope!!'])->assertStatus(401);
    });

    it('passes with the right secret', function () {
        config()->set('health.route.secret', 's3cret');
        $this->getJson('/gated/health', ['x-cron-secret' => 's3cret'])->assertOk()->assertJsonPath('status', 'healthy');
    });

    it('refuses everything when no secret is configured', function () {
        config()->set('health.route.secret', null);
        $this->getJson('/gated/health', ['x-cron-secret' => 'anything'])->assertStatus(503);
    });
});
