<?php

namespace DDTrace\Integrations\Symfony;

use DDTrace\Integrations\Integration;

class SymfonyIntegration
{
    const NAME = 'symfony';
    const BUNDLE_NAME = 'datadog_symfony_bundle';

    public static function load()
    {
        if (!defined('Symfony\Component\HttpKernel\Kernel::VERSION')) {
            return Integration::NOT_LOADED;
        }

        $version = \Symfony\Component\HttpKernel\Kernel::VERSION;

        if (substr($version, 0, 3) === "3.4") {
            V3\SymfonyIntegration::load();
            return Integration::LOADED;
        } elseif (substr($version, 0, 2) === "4.") {
            V4\SymfonyIntegration::load();
            return Integration::LOADED;
        }

        return Integration::NOT_AVAILABLE;
    }
}
