<?php

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;

class PassingCheck extends HealthCheck
{
    public function name(): string { return '📝 Database'; }
    public function check(): HealthCheckResult
    {
        return HealthCheckResult::healthy($this->name(), 'Connected', ['driver' => 'mysql']);
    }
}

class WarningCheck extends HealthCheck
{
    public function name(): string { return 'Queue'; }
    public function check(): HealthCheckResult
    {
        return HealthCheckResult::warning($this->name(), 'Backlog growing');
    }
}

class FailingCheck extends HealthCheck
{
    public function name(): string { return 'Redis'; }
    public function check(): HealthCheckResult
    {
        return HealthCheckResult::failed($this->name(), 'Connection refused');
    }
}

class ThrowingCheck extends HealthCheck
{
    public function name(): string { return 'Explodes'; }
    public function check(): HealthCheckResult
    {
        throw new RuntimeException('boom');
    }
}

it('serves the documented response shape', function () {
    config()->set('health.checks', [PassingCheck::class]);

    $r = $this->getJson('/api/v1/health');

    $r->assertOk()
      ->assertJsonStructure([
          'status', 'instance', 'environment', 'timestamp', 'response_time_ms',
          'checks' => [['key', 'name', 'raw_name', 'status', 'message', 'meta']],
          'summary' => ['total', 'healthy', 'warning', 'failed'],
      ]);
});

it('strips emoji from name but preserves raw_name', function () {
    config()->set('health.checks', [PassingCheck::class]);

    $c = $this->getJson('/api/v1/health')->json('checks.0');

    expect($c['name'])->toBe('Database')
        ->and($c['raw_name'])->toBe('📝 Database')
        ->and($c['key'])->toBe('passing-check');
});

it('returns 200 for warnings because a warning is still up', function () {
    config()->set('health.checks', [WarningCheck::class]);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'warning');
});

it('returns 503 when any check fails', function () {
    config()->set('health.checks', [PassingCheck::class, FailingCheck::class]);

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('summary.failed', 1)
        ->assertJsonPath('summary.healthy', 1);
});

it('reports a throwing check as failed instead of 500ing the endpoint', function () {
    // A broken dependency must not deny the monitor a response.
    config()->set('health.checks', [ThrowingCheck::class]);

    $r = $this->getJson('/api/v1/health');

    $r->assertStatus(503)
      ->assertJsonPath('checks.0.status', 'failed')
      ->assertJsonPath('checks.0.key', 'throwing-check')
      ->assertJsonPath('checks.0.meta.error', 'boom');
});

it('derives the instance from app.url by default', function () {
    config()->set('health.checks', []);

    $this->getJson('/api/v1/health')->assertJsonPath('instance', 'example.test');
});

it('prefers an explicitly configured instance', function () {
    config()->set('health.checks', []);
    config()->set('health.instance', 'hotelixamsterdam.com');

    $this->getJson('/api/v1/health')->assertJsonPath('instance', 'hotelixamsterdam.com');
});

it('is healthy with no checks registered', function () {
    config()->set('health.checks', []);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'healthy')
        ->assertJsonPath('summary.total', 0);
});

class QueueDepthHealthCheck extends HealthCheck
{
    public function name(): string { return 'Queue Depth'; }
    public function check(): HealthCheckResult
    {
        return HealthCheckResult::healthy($this->name(), 'ok');
    }
}

it('strips a HealthCheck suffix when deriving keys, and only then', function () {
    // PassingCheck -> "passing-check" (no suffix to strip)
    // QueueDepthHealthCheck -> "queue-depth"
    // Both the normal path and the exception path must agree on this.
    config()->set('health.checks', [QueueDepthHealthCheck::class, PassingCheck::class]);

    $keys = collect($this->getJson('/api/v1/health')->json('checks'))->pluck('key')->all();

    expect($keys)->toBe(['queue-depth', 'passing-check']);
});
