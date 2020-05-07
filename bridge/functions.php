<?php

namespace DDTrace\Bridge;

/**
 * Tells whether or not tracing is enabled without having to fire the auto-loading mechanism.
 *
 * @return bool
 */
function dd_tracing_enabled()
{
    if ('cli' === PHP_SAPI) {
        return dd_env_as_boolean('DD_TRACE_CLI_ENABLED', dd_env_as_boolean('DD_PHPUNIT_BOOTSTRAP', false));
    }

    return dd_env_as_boolean('DD_TRACE_ENABLED', true);
}

/**
 * Returns the boolean value of an environment variable:
 *  - if NOT defined then returns $default
 *  - if defined and equals (case-insensitive) to 'true' or '1' then returns true
 *  - if defined and equals (case-insensitive) to 'false' or '0' then returns false
 *  - otherwise returns $default
 *
 * @param string $name
 * @param boolean $default
 * @return bool
 */
function dd_env_as_boolean($name, $default)
{
    $envValue = getenv($name);
    if ($envValue === false) {
        return $default;
    }

    $envValue = strtolower(trim($envValue));
    if ('true' === $envValue || '1' === $envValue) {
        return true;
    } elseif ('false' === $envValue || '0' === $envValue) {
        return false;
    } else {
        return $default;
    }
}
