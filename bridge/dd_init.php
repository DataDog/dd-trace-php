<?php

namespace DDTrace\Bridge;

use DDTrace\Bootstrap;

require_once __DIR__ . '/functions.php';

if (!\class_exists('DDTrace\Tracer')) {
    function_exists('dd_trace_disable_in_request') && dd_trace_disable_in_request();
    return;
}

// If users import our package in composer, at least the minor version should match with the installed extension.
if (!dd_minor_semver_matches(\DDTrace\Tracer::VERSION, DD_TRACE_VERSION)) {
    function_exists('dd_trace_disable_in_request') && dd_trace_disable_in_request();
    \error_log(
        'dd-trace-php is disabled because extension minor version and composer package minor version do not match'
    );
    return;
}

// trigger configuration reload to memoize values of all configuration options as set by environment variables
function_exists('dd_trace_internal_fn') && dd_trace_internal_fn('ddtrace_reload_config');
if (!dd_tracing_enabled()) {
    return;
}

Bootstrap::tracerOnce();
require_once __DIR__ . '/../src/DDTrace/Integrations/load_integrations.php';
