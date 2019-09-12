<?php

namespace DDTrace\Integrations\WordPress;

use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\Integrations\WordPress\V4\WordPressIntegrationLoader;

class WordPressSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'wordpress';

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

        // This call happens right after WP registers an autoloader for the first time
        dd_trace_method('Requests', 'set_certificate_path', function () use ($integration) {
            if (!isset($GLOBALS['wp_version']) || !is_string($GLOBALS['wp_version'])) {
                return false;
            }
            $majorVersion = substr($GLOBALS['wp_version'], 0, 2);
            if ('4.' === $majorVersion) {
                $loader = new WordPressIntegrationLoader();
                $loader->load($integration);
            }
            return false; // Drop this span to reduce noise
        });

        return self::LOADED;
    }
}
