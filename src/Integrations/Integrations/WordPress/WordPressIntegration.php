<?php

namespace DDTrace\Integrations\WordPress;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\WordPress\V4\WordPressIntegrationLoader;
use DDTrace\Integrations\WordPress\V6\WordPressComponent;

class WordPressIntegration extends Integration
{
    const NAME = 'wordpress';

    /**
     * @var string
     */
    private $serviceName;

    public function getServiceName()
    {
        if (!empty($this->serviceName)) {
            return $this->serviceName;
        }

        $this->serviceName = \ddtrace_config_app_name(WordPressIntegration::NAME);

        return $this->serviceName;
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

        $integration = $this;

        // This call happens right in central config initialization
        \DDTrace\hook_function('wp_check_php_mysql_versions', null, function () use ($integration) {
            if (!isset($GLOBALS['wp_version']) || !is_string($GLOBALS['wp_version'])) {
                return false;
            }
            $majorVersion = substr($GLOBALS['wp_version'], 0, 1);
            if ($majorVersion >= 4) {
                $loader = new WordPressComponent();
                $loader->load($integration);
            }

            return true;
        });

        return self::LOADED;
    }
}
