<?php

namespace DDTrace\Integrations\Yii;

use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\Util\Versions;

class YiiSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'yii';

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
     * {@inheritdoc}
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return self::NOT_AVAILABLE;
        }

        $integration = self::getInstance();

        // This happens somewhat early in the setup, though there may be a better candidate
        \dd_trace_method('yii\di\Container', '__construct', function () use ($integration) {
            if (Versions::versionMatches('2.0', \Yii::getVersion())) {
                $loader = new V2\YiiIntegrationLoader();
                $loader->load($integration);
            }
            return false; // Drop this span to reduce noise
        });

        return self::LOADED;
    }
}
