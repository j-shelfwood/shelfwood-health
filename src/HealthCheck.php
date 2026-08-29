<?php

namespace Shelfwood\Health;

/**
 * Base class for a health check.
 *
 * Implementations are resolved from the container, so they may type-hint
 * dependencies in their constructor.
 */
abstract class HealthCheck
{
    abstract public function name(): string;

    abstract public function check(): HealthCheckResult;

    /**
     * Stable identifier for matching checks across environments and versions.
     *
     * Derived from the class name: DatabaseWriteHealthCheck -> "database-write".
     * Consumers key off this rather than the display name, so overriding it in
     * a subclass changes the public contract for that check.
     */
    public function key(): string
    {
        $base = class_basename($this);
        $base = preg_replace('/HealthCheck$/', '', $base) ?? $base;
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $base));

        return $kebab ?: strtolower($base);
    }

    /**
     * Execute the check, guaranteeing the result carries this check's key.
     */
    public function run(): HealthCheckResult
    {
        $result = $this->check();

        if ($result->key === null) {
            $result->key = $this->key();
        }

        return $result;
    }
}
