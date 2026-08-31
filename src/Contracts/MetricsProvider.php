<?php

namespace Shelfwood\Health\Contracts;

/**
 * A provider of business/operational metrics for the health document.
 *
 * Metrics are NOT health checks: they carry no status and never affect the
 * endpoint's overall status or HTTP code. They are numbers worth seeing
 * (tenants, uploads this week, open deals) rendered by the monitor as a
 * stats strip. Threshold something instead when it should be able to page.
 *
 * Return a flat map of metric key => scalar. Keys are merged across
 * providers in registration order; later providers win on collision.
 */
interface MetricsProvider
{
    /** @return array<string, int|float|string> */
    public function metrics(): array;
}
