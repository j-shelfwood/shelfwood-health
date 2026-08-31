# shelfwood/health — specification

Laravel health checks with a **stable JSON contract** for external monitoring.

Extracted from a production Laravel app whose `/api/v1/health` endpoint is
consumed by an external monitoring dashboard across many production sites. The contract
below is reproduced from that implementation and must not drift.

## Response contract

`GET /api/v1/health`

```json
{
  "status": "healthy|warning|failed",
  "instance": "example.com",
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
// A multi-tenant app resolving identity via shelfwood/instance-config
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

The route is unauthenticated, matching the originating app's
endpoint, so uptime monitors can reach it without credentials. `meta` can carry
queue depths, versions and connection detail. Worth re-taking that decision per
app: set `route.enabled => false` and register the controller behind auth if the
detail is sensitive.

## Shipped checks

Nine generic checks, bound with real config by the service provider:

| Class | key |
|---|---|
| `DatabaseHealthCheck` | `database` |
| `DatabaseWriteHealthCheck` | `database-write` |
| `RedisHealthCheck` | `redis` |
| `CacheHealthCheck` | `cache` |
| `SessionHealthCheck` | `session` |
| `StorageHealthCheck` | `storage` |
| `MetricsCacheHealthCheck` | `metrics-cache` |
| `SentryHealthCheck` | `sentry` |
| `MattermostHealthCheck` | `mattermost` |

**Every one is bound, not autowired**, and since v1.0.1 every check ALSO
falls back to live config when constructed with null args
(`config('database.default')`, `config('cache.default')`, …). The bindings
remain the primary path — but a resolution that bypasses them (a
`class_alias` shim resolves against the canonical class name, not the alias;
found in the wild 2026-08-31) now reads the app's real config instead of
reporting confident nonsense against hardcoded defaults. Bindings use
`bindIf`, so an app can override any single one.

`StorageHealthCheck` reads `config('health.storage_disks')`, default
`['local', 'public']`.

### Deliberately NOT shipped

Four checks were in the extraction plan and were dropped after reading them:

| Check | Why it stays in the app |
|---|---|
| `QueueHealthCheck` | Takes `ScheduledJobActivityTracker`, an app class |
| `SchedulerHealthCheck` | Depends on `App\Models\SystemHeartbeat`, an app Eloquent model |
| `BrevoHealthCheck` | Uses `Instance::services()` / `Instance::mail()` — the instance-config dependency this package deliberately avoids |
| `BackupHealthCheck` | Assumes **Spatie Laravel Backup**; shipping it would break every app without that package |

A generic-looking filename is not evidence a check is generic. All four would
have compiled fine in the package and failed at runtime in a fresh app.

## Status

**P1.1–P1.4 complete**, plus **P1.3** (9 of the intended 13 checks moved; the
other 4 reclassified above). 18 tests, 57 assertions passing against Testbench.

Adopted in its originating application, verified against a response captured
from a live site before the change: identical top-level fields, identical
per-check field set, identical key order, identical names.
