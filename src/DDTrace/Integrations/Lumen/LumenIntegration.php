<?php

namespace DDTrace\Integrations\Lumen;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Lumen\V5\LumenIntegrationLoader;

final class LumenIntegration extends Integration
{
    const NAME = 'lumen';

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
        dd_trace('Laravel\Lumen\Application', '__construct', function () {

            if (0 === strpos($this->version(), 'Lumen (5.')) {
                $loader = new LumenIntegrationLoader();
                $loader->load();
            }

            return dd_trace_forward_call();
        });

        return Integration::LOADED;
    }
}
