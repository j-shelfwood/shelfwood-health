# shelfwood/health — specification

Laravel health checks with a **stable JSON contract** for external monitoring.

Extracted from `prj-more-apartments`, whose `/api/v1/health` endpoint is
consumed by `monitor.shelfwood.co` across 14 production sites. The contract
below is reproduced from that implementation and must not drift.

## Response contract

`GET /api/v1/health`

```json
{
  "status": "healthy|warning|failed",
  "instance": "hotelixamsterdam.com",
  "environment": "production",
  "timestamp": "2026-01-12T18:00:00+00:00",
  "response_time_ms": 12.34,
  "checks": [
    {
      "key": "database",
      "name": "Database",
      "raw_name": "📝 Database",
      "status": "healthy",
      "message": "Connected",
      "meta": {"driver": "mysql"}
    }
  ],
  "summary": {"total": 10, "healthy": 9, "warning": 1, "failed": 0}
}
```

**Compatibility rules.** Adding fields is safe. Renaming or removing them is a
breaking change for every consumer. `checks[].key` is what monitors match on —
`name` is for humans and may change freely.

### HTTP status

| Overall | Code | Why |
|---|---|---|
| `healthy` | 200 | |
| `warning` | **200** | A warning is still *up*. Monitors read the body to see degradation. |
| `failed` | **503** | |

A consumer that only looks at the status code cannot distinguish healthy from
degraded. That is intentional: uptime checks care about up/down, dashboards read
the body.

### `key` derivation

From the class name, with a `HealthCheck` suffix stripped if present, then
kebab-cased:

- `DatabaseWriteHealthCheck` → `database-write`
- `QueueDepthHealthCheck` → `queue-depth`
- `PassingCheck` → `passing-check` (no suffix to strip)

The normal path and the exception path derive keys identically — pinned by a
test, because they are separate code paths that could silently diverge.

## Behaviour

- **A throwing check never takes the endpoint down.** It becomes a `failed`
  result with `meta.error`, `meta.exception` and `meta.check_class`. A broken
  dependency must not deny the monitor a response.
- **Checks resolve from the container**, so they may type-hint dependencies.
- **Order is preserved** from `config('health.checks')`.
- **No checks registered → `healthy`, `total: 0`.** An app that registers
  nothing is not thereby unhealthy.

## Installation

```bash
composer require shelfwood/health
php artisan vendor:publish --tag=health-config
```

Then register checks in `config/health.php`.

## Configuration

| Key | Default | Notes |
|---|---|---|
| `checks` | `[]` | Ordered list of `HealthCheck` classes |
| `route.enabled` | `true` | `false` to wire the controller up yourself |
| `route.uri` | `api/v1/health` | |
| `route.middleware` | `['throttle:health']` | The limiter is auto-registered unless the app defines one |
| `instance` | `env('HEALTH_INSTANCE')` | Null → host of `config('app.url')` |
| `rate_limit.attempts` | `60` | Per `rate_limit.per_minutes` |

## Instance identity

The `instance` field comes from `Shelfwood\Health\Contracts\InstanceIdentifier`.
The default reads config. Multi-instance apps bind their own:

```php
// prj-more-apartments, which resolves identity via shelfwood/instance-config
$this->app->bind(InstanceIdentifier::class, fn () => new class implements InstanceIdentifier {
    public function id(): string { return Instance::id(); }
});
```

The package deliberately does **not** depend on `shelfwood/instance-config` —
multi-tenancy is not a health concern.

## Writing a check

```php
use Shelfwood\Health\{HealthCheck, HealthCheckResult};

class RedisHealthCheck extends HealthCheck
{
    public function name(): string { return 'Redis'; }

    public function check(): HealthCheckResult
    {
        try {
            Redis::ping();
            return HealthCheckResult::healthy($this->name(), 'Connected');
        } catch (\Throwable $e) {
            return HealthCheckResult::failed($this->name(), $e->getMessage());
        }
    }
}
```

`meta` is free-form and passed through untouched. **It is unauthenticated by
default** — do not put anything in it you would not publish.

## Security

The route is unauthenticated, matching the existing `prj-more-apartments`
endpoint, so uptime monitors can reach it without credentials. `meta` can carry
queue depths, versions and connection detail. Worth re-taking that decision per
app: set `route.enabled => false` and register the controller behind auth if the
detail is sensitive.

## Status

**P1.1–P1.4 complete** (scaffold, contract types, controller, route, config).
14 tests, 47 assertions passing against Testbench.

Not yet done: **P1.3** moving the 13 generic checks from `prj-more-apartments`,
and **P1.5** adopting the package there — the regression gate for which is
byte-identical JSON from a live site.
