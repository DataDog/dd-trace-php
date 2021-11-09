<?php

namespace DDTrace\Bridge;

use DDTrace\Bootstrap;
use DDTrace\Integrations\IntegrationsLoader;

if (\PHP_VERSION_ID < 70000) {
    \date_default_timezone_set(@\date_default_timezone_get());
}

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

// trigger configuration reload to memoize values of all configuration options as set by environment variables
function_exists('dd_trace_internal_fn') && \dd_trace_internal_fn('ddtrace_reload_config');
if (!dd_tracing_enabled()) {
    \dd_trace_disable_in_request();
    return;
}

// Required classes and functions
require __DIR__ . '/autoload.php';

// Optional classes and functions
require __DIR__ . '/dd_register_optional_deps_autoloader.php';

Bootstrap::tracerOnce();
IntegrationsLoader::load();
