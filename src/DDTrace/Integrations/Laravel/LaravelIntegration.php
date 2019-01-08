<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\Integrations\Integration;

class LaravelIntegration
{
    const NAME = 'laravel';

    public static function load()
    {
        if (!defined('Illuminate\Foundation\Application::VERSION')) {
            return Integration::NOT_LOADED;
        }

        $version = \Illuminate\Foundation\Application::VERSION;

        if (substr( $version, 0, 3 ) === "4.2") {
            \DDTrace\Integrations\Laravel\V4\LaravelIntegration::load();
            return Integration::LOADED;;
        } elseif (substr( $version, 0, 2 ) === "5.") {
            \DDTrace\Integrations\Laravel\V5\LaravelIntegration::load();
            return Integration::LOADED;;
        }

        return Integration::NOT_AVAILABLE;
    }
}
