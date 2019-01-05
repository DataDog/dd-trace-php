<?php

namespace DDTrace\Integrations\Symfony;

class SymfonyIntegration
{
    const NAME = 'symfony';
    const BUNDLE_NAME = 'datadog_symfony_bundle';

    public static function load()
    {
        if (!defined('Symfony\Component\HttpKernel\Kernel::VERSION')) {
            return false;
        }

        $version = \Symfony\Component\HttpKernel\Kernel::VERSION;

        if (substr( $version, 0, 3 ) === "3.4") {
            V3\SymfonyIntegration::load();
            return true;
        } elseif (substr( $version, 0, 2 ) === "4.") {
            V4\SymfonyIntegration::load();
            return true;
        }

        return false;
    }
}
