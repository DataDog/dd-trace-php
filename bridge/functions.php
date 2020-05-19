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
        return dd_env_as_boolean('DD_TRACE_CLI_ENABLED', false);
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

/**
 * Registers the Datadog.
 */
function dd_register_autoloader()
{
    require_once __DIR__ . '/dd_required_deps_autoloader.php';
    require_once __DIR__ . '/dd_optional_deps_autoloader.php';

    spl_autoload_register(['\DDTrace\Bridge\OptionalDepsAutoloader', 'load'], true, true);
    spl_autoload_register(['\DDTrace\Bridge\RequiredDepsAutoloader', 'load'], true, true);
}

/**
 * Unregisters the Datadog.
 */
function dd_unregister_autoloader()
{
    spl_autoload_unregister(['\DDTrace\Bridge\RequiredDepsAutoloader', 'load']);
    spl_autoload_unregister(['\DDTrace\Bridge\OptionalDepsAutoloader', 'load']);
}

/**
 * Traces spl_autoload_register in order to provide hooks for auto-instrumentation to be executed.
 */
function dd_wrap_autoloader()
{
    dd_register_autoloader();

    /* CodeIgniter v2 does not use an autoloader. Tracing the CI_Hooks
     * constructor let's us set up the world because it is called very early
     * in CodeIgniter's startup process before we need to trace anything.
     *
     * Note that this hook cannot use dd_trace_method, since dd_trace_method
     * will attempt to create a span before everything we need to make spans
     * has been set up, it puts us in a bad state.
     */
    \dd_trace('CI_Hooks', '__construct', function () {
        require __DIR__ . '/dd_init.php';
        // Since the above line initializes ddtrace, we don't need the autoloader to do it
        \dd_untrace('spl_autoload_register');
        return \dd_trace_forward_call();
    });

    // User app is not using any autoloader we just import the initialization script
    if (dd_env_as_boolean('DD_TRACE_NO_AUTOLOADER', false)) {
        require __DIR__ . '/dd_init.php';
        return;
    }

    dd_trace('spl_autoload_register', function () {
        $originalAutoloaderRegistered = dd_trace_forward_call();
        $args = func_get_args();
        if (sizeof($args) == 0) {
            return $originalAutoloaderRegistered;
        }

        list($loader) = $args;
        $syntax_only = true;
        $callable_name = '';
        // callable_name is passed by-reference
        if (!\is_callable($loader, $syntax_only, $callable_name)) {
            return $originalAutoloaderRegistered;
        }

        /* Composer auto-generates a class loader with a name like:
         * ComposerAutoloaderInitaa9e6eaaeccc2dd24059c64bd3ff094c
         */
        if (\strpos($callable_name, "ComposerAutoloaderInit") === 0) {
            return $originalAutoloaderRegistered;
        }

        // In case of composer, we need to be in front of it so our generated classes are loaded,
        // including non-psr4 classes and files.
        // This will not be required anymore once we move in the next release to a non wrapped autoloader.
        dd_unregister_autoloader();
        dd_register_autoloader();

        dd_untrace('spl_autoload_register');
        require __DIR__ . '/dd_init.php';
    });
}
