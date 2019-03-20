<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Laravel\V5\LaravelIntegrationLoader;
use DDTrace\Integrations\AbstractIntegration;
use DDTrace\Util\Versions;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
final class LaravelIntegration extends AbstractIntegration
{
    const NAME = 'laravel';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Loads the integration.
     *
     * @return int
     */
    public static function load()
    {
        $instance = new self();
        return $instance->doLoad();
    }

    /**
     * @return int
     */
    public function doLoad()
    {
        $kernelClass = null;

        dd_trace('Illuminate\Foundation\Application', '__construct', function () {

            $version = \Illuminate\Foundation\Application::VERSION;
            if (Versions::versionMatches("4.2", $version)) {
                \DDTrace\Integrations\Laravel\V4\LaravelIntegration::load();
            } elseif (Versions::versionMatches("5", $version)) {
                $loader = new LaravelIntegrationLoader();
                $loader->load();
            }

            return call_user_func_array([$this, '__construct'], func_get_args());
        });

        return Integration::LOADED;
    }
}
