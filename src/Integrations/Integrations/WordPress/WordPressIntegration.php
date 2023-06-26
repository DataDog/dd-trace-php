<?php

namespace DDTrace\Integrations\WordPress;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Wordpress\OTel\OTelIntegrationLoader;
use DDTrace\Integrations\WordPress\V4\WordPressIntegrationLoader;
use DDTrace\Integrations\WordPress\V6\WordPressComponent;

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

        // This call happens right in central config initialization
        \DDTrace\hook_function('wp_check_php_mysql_versions', null, function () use ($integration) {
            if (!isset($GLOBALS['wp_version']) || !is_string($GLOBALS['wp_version'])) {
                return false;
            }
            $majorVersion = substr($GLOBALS['wp_version'], 0, 1);
            if ($majorVersion >= 4) {
                $loader = new WordPressIntegrationLoader();

                //$loader = new OTelIntegrationLoader();

                //$service = \ddtrace_config_app_name(self::NAME);
                //$loader = new WordPressComponent($service);

                $loader->load($integration);
            }
        });

        return self::LOADED;
    }
}
