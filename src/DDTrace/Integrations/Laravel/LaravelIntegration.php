<?php

namespace DDTrace\Integrations\Laravel;

class LaravelIntegration
{
    const NAME = 'laravel';

    public static function load()
    {
        if (!defined('Illuminate\Foundation\Application::VERSION')) {
            return false;
        }

        $version = \Illuminate\Foundation\Application::VERSION;

        if (substr( $version, 0, 3 ) === "4.2") {
            \DDTrace\Integrations\Laravel\V4\LaravelIntegration::load();
            return true;
        } elseif (substr( $version, 0, 2 ) === "5.") {
            \DDTrace\Integrations\Laravel\V5\LaravelIntegration::load();
            return true;
        }

        return false;
    }
}
