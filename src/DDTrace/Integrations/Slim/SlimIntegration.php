<?php

namespace DDTrace\Integrations\Slim;

use DDTrace\Configuration;
use DDTrace\Integrations\Integration;

class SlimIntegration extends Integration
{
    const NAME = 'slim';

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
        if (!self::shouldLoad(self::NAME)) {
            return self::NOT_AVAILABLE;
        }

        $integration = self::getInstance();

        // Slim v2
        // Web bootstrap
        // Add tracing entry point: Slim\Slim::__construct

        // Slim v3 & v4
        // Web bootstrap
        dd_trace('Slim\App', '__construct', function () use ($integration) {
            $majorVersion = substr(self::VERSION, 0, 1);
            if ('3' === $majorVersion) {
                $loader = new V3\SlimIntegrationLoader();
                $loader->load($integration);
                return dd_trace_forward_call();
            }
            if ('4' === $majorVersion) {
                // Add Slim v4 loader here
                return dd_trace_forward_call();
            }
            return dd_trace_forward_call();
        });

        // Slim v3 & v4 have no CLI bootstrap

        return self::LOADED;
    }

    public static function getAppName()
    {
        return \ddtrace_config_app_name(self::NAME);
    }
}
