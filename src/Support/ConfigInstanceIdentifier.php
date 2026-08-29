<?php

namespace Shelfwood\Health\Support;

use Shelfwood\Health\Contracts\InstanceIdentifier;

/**
 * Default identifier: the host of config('app.url'), else the app name.
 */
class ConfigInstanceIdentifier implements InstanceIdentifier
{
    public function id(): string
    {
        $configured = config('health.instance');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $url = (string) config('app.url', '');
        $host = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;

        if (is_string($host) && $host !== '') {
            return $host;
        }

        return (string) config('app.name', 'unknown');
    }
}
