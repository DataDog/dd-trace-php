<?php

namespace DDTrace\Configuration;

/**
 * Registry interface for configuration stores.
 */
interface Registry
{
    /**
     * Extract a boolean configuration value, providing a default if the value has not been configured.
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public function boolValue($key, $default);
}
