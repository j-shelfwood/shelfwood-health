<?php

namespace Shelfwood\Health;

/**
 * The outcome of one health check.
 *
 * `meta` is free-form and is passed through to the JSON response untouched;
 * consumers treat unknown keys as opaque.
 */
class HealthCheckResult
{
    public function __construct(
        public string $name,
        public string $message,
        public HealthCheckStatus $status,
        public ?array $meta = null,
        public ?string $key = null
    ) {}

    public static function healthy(string $name, string $message, ?array $meta = null, ?string $key = null): self
    {
        return new self($name, $message, HealthCheckStatus::Healthy, $meta, $key);
    }

    public static function warning(string $name, string $message, ?array $meta = null, ?string $key = null): self
    {
        return new self($name, $message, HealthCheckStatus::Warning, $meta, $key);
    }

    public static function failed(string $name, string $message, ?array $meta = null, ?string $key = null): self
    {
        return new self($name, $message, HealthCheckStatus::Failed, $meta, $key);
    }
}
