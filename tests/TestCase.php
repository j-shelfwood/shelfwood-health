<?php

namespace Shelfwood\Health\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Shelfwood\Health\HealthServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HealthServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://example.test');
    }
}
