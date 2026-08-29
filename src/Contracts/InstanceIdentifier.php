<?php

namespace Shelfwood\Health\Contracts;

/**
 * Supplies the "instance" field of the health response.
 *
 * prj-more-apartments resolves this via shelfwood/instance-config
 * (Instance::id()), but that is a multi-tenant concern and NOT a dependency
 * this package should carry. Apps with a single identity get a sane default
 * (config('app.url') host); multi-instance apps bind their own resolver.
 */
interface InstanceIdentifier
{
    public function id(): string;
}
