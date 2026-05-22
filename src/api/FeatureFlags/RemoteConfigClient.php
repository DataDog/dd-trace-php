<?php

namespace DDTrace\FeatureFlags;

final class RemoteConfigClient
{
    private $hasConfig;
    private $configVersion;

    public function __construct($hasConfig = null, $configVersion = null)
    {
        if ($hasConfig !== null && !is_callable($hasConfig)) {
            throw new \InvalidArgumentException('Expected a callable config availability reader');
        }

        if ($configVersion !== null && !is_callable($configVersion)) {
            throw new \InvalidArgumentException('Expected a callable config version reader');
        }

        $this->hasConfig = $hasConfig ?: function () {
            return function_exists('DDTrace\\ffe_has_config') && \DDTrace\ffe_has_config();
        };
        $this->configVersion = $configVersion ?: function () {
            return function_exists('DDTrace\\ffe_config_version') ? \DDTrace\ffe_config_version() : 0;
        };
    }

    public static function isAvailable()
    {
        return function_exists('DDTrace\\ffe_has_config')
            && function_exists('DDTrace\\ffe_config_version');
    }

    public function hasConfig()
    {
        return (bool) call_user_func($this->hasConfig);
    }

    public function configVersion()
    {
        $version = call_user_func($this->configVersion);
        return is_int($version) ? $version : (int) $version;
    }
}
