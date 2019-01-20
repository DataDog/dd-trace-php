<?php

namespace DDTrace\Integrations;

use DDTrace\Integrations\Laravel\V5\LaravelProvider as LaravelV5Provider;


/**
 * @deprecated: see -> DDTrace\Integrations\Laravel\V5\LaravelProvider
 */
class LaravelProvider extends LaravelV5Provider
{
    /**
     * A proxy to the new integration, temporarily left here for backward compatibility.
     */
    public function register()
    {
        error_log('DEPRECATED: Class "DDTrace\Integrations\LaravelProvider" will be removed soon, '
            . 'you should use the new integration in "DDTrace\Integrations\Laravel" package');
        return parent::register();
    }
}
