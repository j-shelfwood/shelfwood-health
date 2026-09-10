<?php

namespace Shelfwood\Health;

/**
 * Status of a single health check, and of the run as a whole.
 *
 * The string values are part of the public JSON contract consumed by
 * external monitoring dashboards - do not rename them.
 */
enum HealthCheckStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Failed = 'failed';

    /**
     * The check ran but had nothing to judge: no data in its window, or the
     * upstream it compares against returned nothing. This is NOT a degradation
     * and must never page — but it must not read as healthy either, because
     * "verified fifty bookings" and "verified none" are different claims.
     *
     * It contributes to no summary bucket, so it cannot mask a real failure.
     * Consumers that do not recognise it fold it to unknown already
     * (monitor.shelfwood.co: normalizeStatus).
     */
    case Unknown = 'unknown';

    public function emoji(): string
    {
        return match ($this) {
            self::Healthy => '✅',
            self::Warning => '⚠️',
            self::Failed => '❌',
            self::Unknown => '◌',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy => '0x2ECC71',
            self::Warning => '0xF1C40F',
            self::Failed => '0xE74C3C',
            self::Unknown => '0x95A5A6',
        };
    }
}
