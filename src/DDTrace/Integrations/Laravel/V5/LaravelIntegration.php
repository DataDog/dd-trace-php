<?php

namespace DDTrace\Integrations\Laravel\V5;

class LaravelIntegration
{
    public static function load()
    {
        dd_trace('Illuminate\Foundation\ProviderRepository', 'load', function(array $providers) {
            return $this->load(array_merge($providers, ['DDTrace\Integrations\Laravel\V5\LaravelProvider']));
        });
    }
}
