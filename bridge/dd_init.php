<?php

namespace DDTrace\Bridge;

use DDTrace\Bootstrap;
use DDTrace\Format;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\SpanContext;

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

// This gets called before curl_exec() calls from the C extension on PHP 5
function curl_inject_distributed_headers($ch, array $headers)
{
    if (
        !\class_exists('DDTrace\\SpanContext', false)
        || !\class_exists('DDTrace\\GlobalTracer', false)
        || !\class_exists('DDTrace\\Format', false)
    ) {
        return;
    }
    $span = GlobalTracer::get()->getActiveSpan();
    if (null === $span) {
        return;
    }
    $context = $span->getContext();
    if (!\property_exists($context, 'origin')) {
        return;
    }

    /*
     * We can't use the existing context because only userland spans are represented.
     * As a result, we create a new context with dd_trace_peek_span_id() to get the
     * active span ID.
     */
    $newContext = new SpanContext($context->getTraceId(), \dd_trace_peek_span_id());
    $newContext->origin = $context->origin;

    GlobalTracer::get()->inject($newContext, Format::CURL_HTTP_HEADERS, $headers);

    \curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);
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
