<?php

namespace Shelfwood\Health;

/**
 * Status of a single health check, and of the run as a whole.
 *
 * The string values are part of the public JSON contract consumed by
 * monitor.shelfwood.co - do not rename them.
 */
enum HealthCheckStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Failed = 'failed';

    public function emoji(): string
    {
        return match ($this) {
            self::Healthy => '✅',
            self::Warning => '⚠️',
            self::Failed => '❌',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy => '0x2ECC71',
            self::Warning => '0xF1C40F',
            self::Failed => '0xE74C3C',
        };
    }
}
