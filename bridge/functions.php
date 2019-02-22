<?php

namespace DDTrace\Bridge;

/**
 * Tells whether or not tracing is enabled without having to fire the auto-loading mechanism.
 *
 * @return bool
 */
function dd_tracing_enabled()
{
    $value = getenv('DD_TRACE_ENABLED');
    if (false === $value) {
        // Not setting the env means we default to enabled.
        return true;
    }

    $value = trim(strtolower($value));
    if ($value === '0' || $value === 'false') {
        return false;
    } else {
        return true;
    }
}

/**
 * Checks if any of the provided classes exists.
 *
 * @param string[] $sentinelClasses
 * @return bool
 */
function any_class_exists(array $sentinelClasses)
{
    foreach ($sentinelClasses as $sentinelClass) {
        if (class_exists($sentinelClass)) {
            return true;
        }
    }

    return false;
}

/**
 * Registers the Datadog.
 */
function dd_register_autoloader()
{
    require_once __DIR__ . '/dd_autoloader.php';
    spl_autoload_register(['\DDTrace\Bridge\Autoloader', 'load'], true, true);
}

/**
 * Traces spl_autoload_register in order to provide hooks for auto-instrumentation to be executed.
 */
function dd_wrap_autoloader()
{
    dd_register_autoloader();
    require __DIR__ . '/dd_init.php';
}
