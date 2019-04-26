<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Laravel\V5\LaravelIntegrationLoader;
use DDTrace\Util\Versions;
use DDTrace\Time;
use DDTrace\GlobalTracer;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
class LaravelIntegration extends Integration
{
    const NAME = 'laravel';

    /**
     * @var self
     */
    private static $instance;

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
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
            \DDTrace\Integrations\Laravel\LaravelIntegration::updateStartTime();

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

    public static function updateStartTime()
    {
        if (defined('LARAVEL_START')) {
            $tracer = GlobalTracer::get();
            $rootSpan = $tracer->getSafeRootSpan();
            if (is_subclass_of($rootSpan, '\DDTrace\Data\Span')) {
                $rootSpan->startTime = Time::fromMicrotime(LARAVEL_START);
            }
        }
    }
}
