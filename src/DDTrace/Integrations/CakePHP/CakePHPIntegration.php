<?php

namespace DDTrace\Integrations\CakePHP;

use DDTrace\Configuration;
use DDTrace\Integrations\CakePHP\V2\CakePHPIntegrationLoader;
use DDTrace\Integrations\Integration;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
class CakePHPIntegration extends Integration
{
    const NAME = 'cakephp';

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

        // CakePHP v2.x - we don't need to check for v3 since it does not have \Dispatcher or \ShellDispatcher
        $initCakeV2 = function () use ($integration) {
            $loader = new CakePHPIntegrationLoader();
            $loader->load($integration);
            return dd_trace_forward_call();
        };
        // Web bootstrap
        dd_trace('Dispatcher', '__construct', $initCakeV2);
        // CLI bootstrap
        // Once auto-instrumentation has been added for non-autoloaded projects,
        // uncomment the following line and delete the if block below it
        //dd_trace('ShellDispatcher', 'run', $initCakeV2);
        if ('cli' === PHP_SAPI) {
            dd_trace('App', 'init', $initCakeV2);
        }

        // Trace CakePHP v3 entry point here...

        return self::LOADED;
    }
}
