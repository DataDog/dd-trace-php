<?php

namespace DDTrace\Integrations\Laravel\V4;

use Illuminate\Foundation\Application;

class LaravelIntegration
{
    public static function load()
    {
        dd_trace('Illuminate\Foundation\ProviderRepository', 'load', function (Application $app, array $providers) {
            return $this->load($app, array_merge($providers, ['\DDTrace\Integrations\Laravel\V4\LaravelProvider']));
        });
    }
}
