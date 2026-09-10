<?php

use Shelfwood\Health\HealthCheckResult;
use Shelfwood\Health\HealthCheckStatus;

/**
 * Added 2026-09-10 with the Unknown case. A check that ran but judged nothing
 * is neither healthy nor degraded. Nine More Apartments instances read amber
 * for "no recent BM bookings in the window" — noise for a state that is
 * entirely expected, and the estate already has the rule for it elsewhere
 * ("idle is not unknown, silence expected").
 */
it('exposes unknown for a check that had nothing to judge', function () {
    $r = HealthCheckResult::unknown('🧾 Parity', 'no recent bookings in the window');

    expect($r->status)->toBe(HealthCheckStatus::Unknown)
        ->and($r->status->value)->toBe('unknown')
        ->and($r->message)->toBe('no recent bookings in the window');
});

it('keeps unknown out of every summary bucket so it cannot mask a failure', function () {
    $results = collect([
        HealthCheckResult::healthy('a', 'ok'),
        HealthCheckResult::unknown('b', 'nothing to judge'),
        HealthCheckResult::failed('c', 'broken'),
    ]);

    // HealthController folds the overall status from these three counts. An
    // unknown contributes to none of them, so it can neither degrade a healthy
    // run nor hide a failing one.
    expect($results->filter(fn ($r) => $r->status === HealthCheckStatus::Healthy)->count())->toBe(1)
        ->and($results->filter(fn ($r) => $r->status === HealthCheckStatus::Warning)->count())->toBe(0)
        ->and($results->filter(fn ($r) => $r->status === HealthCheckStatus::Failed)->count())->toBe(1);
});

it('carries an emoji and colour like every other case', function () {
    // match() over the enum is exhaustive; a new case without these arms is a
    // fatal error at runtime, not a test failure. Pin them.
    expect(HealthCheckStatus::Unknown->emoji())->toBe('◌')
        ->and(HealthCheckStatus::Unknown->color())->toBe('0x95A5A6');
});

it('keeps the four public contract strings stable', function () {
    // These values are consumed by monitor.shelfwood.co; renaming one is a
    // breaking change to every dashboard reading the document.
    expect(array_map(fn ($c) => $c->value, HealthCheckStatus::cases()))
        ->toBe(['healthy', 'warning', 'failed', 'unknown']);
});
