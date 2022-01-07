<?php

namespace DDTrace\Integrations\WordPress;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\WordPress\V4\WordPressIntegrationLoader;

class WordPressIntegration extends Integration
{
    const NAME = 'wordpress';

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

        $integration = $this;

        // This call happens right after WP registers an autoloader for the first time
        \DDTrace\hook_method('Requests', 'set_certificate_path', null, function () use ($integration) {
            if (!isset($GLOBALS['wp_version']) || !is_string($GLOBALS['wp_version'])) {
                return false;
            }
            $majorVersion = substr($GLOBALS['wp_version'], 0, 2);
            if ('4.' === $majorVersion || '5.' === $majorVersion) {
                $loader = new WordPressIntegrationLoader();
                $loader->load($integration);
            }
        });

        return self::LOADED;
    }
}
