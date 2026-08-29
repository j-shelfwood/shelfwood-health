<?php

use Shelfwood\Health\HealthCheck;
use Shelfwood\Health\HealthCheckResult;
use Shelfwood\Health\HealthCheckStatus;

class DatabaseWriteHealthCheck extends HealthCheck
{
    public function name(): string { return 'Database Write'; }
    public function check(): HealthCheckResult
    {
        return HealthCheckResult::healthy($this->name(), 'ok');
    }
}

it('derives a kebab-case key from the class name', function () {
    // The key is the contract consumers match on, so this mapping is load-bearing.
    expect((new DatabaseWriteHealthCheck)->key())->toBe('database-write');
});

it('stamps the key onto results that omit it', function () {
    expect((new DatabaseWriteHealthCheck)->run()->key)->toBe('database-write');
});

it('does not overwrite an explicitly set key', function () {
    $check = new class extends HealthCheck {
        public function name(): string { return 'Custom'; }
        public function check(): HealthCheckResult
        {
            return HealthCheckResult::healthy($this->name(), 'ok', null, 'explicit-key');
        }
    };

    expect($check->run()->key)->toBe('explicit-key');
});

it('exposes the documented status strings', function () {
    expect(HealthCheckStatus::Healthy->value)->toBe('healthy')
        ->and(HealthCheckStatus::Warning->value)->toBe('warning')
        ->and(HealthCheckStatus::Failed->value)->toBe('failed');
});

it('builds results through named constructors', function () {
    expect(HealthCheckResult::healthy('A', 'm')->status)->toBe(HealthCheckStatus::Healthy)
        ->and(HealthCheckResult::warning('A', 'm')->status)->toBe(HealthCheckStatus::Warning)
        ->and(HealthCheckResult::failed('A', 'm')->status)->toBe(HealthCheckStatus::Failed);
});
