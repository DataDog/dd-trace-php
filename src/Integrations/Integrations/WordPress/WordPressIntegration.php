<?php

namespace DDTrace\Integrations\WordPress;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\WordPress\V4;
use DDTrace\Integrations\WordPress\V6;

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
            $majorVersion = strstr($GLOBALS['wp_version'], ".", $before_needle = true);
            switch ($majorVersion) {
                case 4:
                case 5:
                    $loader = new V4\WordPressIntegrationLoader();
                    return $loader->load($integration);

                case 6:
                    if (\PHP_VERSION_ID < 70000) {
                        // Although WordPress 6 supports PHP 5.6+, we've stopped enhancing PHP 5.
                        return self::NOT_AVAILABLE;
                    }
                    $service = \ddtrace_config_app_name(self::NAME);
                    $loader = new V6\WordPressComponent($service);
                    return $loader->load($integration);
            }
        });

        return self::LOADED;
    }
}
