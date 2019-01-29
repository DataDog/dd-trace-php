<?php

namespace DDTrace\Integrations\Symfony;

use DDTrace\Integrations\Integration;
use DDTrace\Util\Versions;

class SymfonyIntegration
{
    const NAME = 'symfony';
    const BUNDLE_NAME = 'datadog_symfony_bundle';

    public static function load()
    {
        if (!defined('\Symfony\Component\HttpKernel\Kernel::VERSION')) {
            return Integration::NOT_LOADED;
        }

        $version = \Symfony\Component\HttpKernel\Kernel::VERSION;

        if (Versions::versionMatches('3.4', $version) || Versions::versionMatches('3.3', $version)) {
            V3\SymfonyIntegration::load();
            return Integration::LOADED;
        } elseif (Versions::versionMatches('4', $version)) {
            V4\SymfonyIntegration::load();
            return Integration::LOADED;
        }

        return Integration::NOT_AVAILABLE;
    }
}
