<?php

namespace DDTrace\Integrations\Nette;

use DDTrace\Integrations\Integration;

class NetteIntegration extends Integration
{

    const NAME = 'nette';

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
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    public static function load()
    {
        if (!self::shouldLoad(self::NAME)) {
            return self::NOT_AVAILABLE;
        }

        $integration = self::getInstance();

        dd_trace('Nette\Configurator', '__construct', function () use ($integration) {
            $loader = new NetteLoader();
            $loader->load($integration);
            dd_trace_forward_call();
        });


        return self::LOADED;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }
}
