<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Laravel\V5\LaravelIntegrationLoader;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
class LaravelIntegration
{
    const NAME = 'laravel';

    /**
     * Loads the integration.
     *
     * @return int
     */
    public static function load()
    {
        if (!defined('Illuminate\Foundation\Application::VERSION')) {
            return Integration::NOT_LOADED;
        }

        $version = \Illuminate\Foundation\Application::VERSION;

        if (substr($version, 0, 3) === "4.2") {
            \DDTrace\Integrations\Laravel\V4\LaravelIntegration::load();
            return Integration::LOADED;
        } elseif (substr($version, 0, 2) === "5.") {
            $loader = new LaravelIntegrationLoader();
            return $loader->load();
        }

        return Integration::NOT_AVAILABLE;
    }
}
