<?php

namespace DDTrace\Integrations\ZendFramework;

use DDTrace\Integrations\Integration;

/**
 * Zend framework integration loader.
 */
class ZendFrameworkIntegration extends Integration
{
    const NAME = 'zendframework';

    /**
     * Loads the zend framework integration.
     *
     * @return int
     */
    public static function load()
    {
        if (!self::shouldLoad(self::NAME)) {
            return self::NOT_AVAILABLE;
        }

        if (!defined('Zend_Version::VERSION')) {
            return Integration::NOT_LOADED;
        }

        $version = \Zend_Version::VERSION;
        if (substr($version, 0, 5) === "1.12.") {
            self::loadV1();
            return Integration::LOADED;
        }

        return Integration::NOT_LOADED;
    }

    /**
     * Loads version 1 of zend framework.
     */
    private static function loadV1()
    {
        dd_trace('Zend_Application', 'setOptions', function () {
            $args = func_get_args();
            $options = $args[0];

            $classExist = class_exists('DDTrace_Ddtrace');
            if (!$classExist && !isset($options['resources']['ddtrace'])) {
                $options['autoloaderNamespaces'][] = 'DDTrace_';
                $options['pluginPaths']['DDTrace'] = __DIR__ . '/V1';
                $options['resources']['ddtrace'] = true;
            }

            return call_user_func_array([$this, 'setOptions'], [$options]);
        });
    }
}
