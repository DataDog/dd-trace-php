<?php

namespace DDTrace\Bridge;

if (
    !defined('DD_TRACE_VERSION')
    || !file_exists(__DIR__ . '/../src/DDTrace/version.php')
) {
    function_exists('dd_trace_disable_in_request') && dd_trace_disable_in_request();
    return;
}
if (DD_TRACE_VERSION !== include __DIR__ . '/../src/DDTrace/version.php') {
    function_exists('dd_trace_disable_in_request') && dd_trace_disable_in_request();
    return;
}

if (PHP_VERSION_ID < 70000) {
    date_default_timezone_set(@date_default_timezone_get());
}

// trigger configuration reload to memoize values of all configuration options as set by environment variables
function_exists('dd_trace_internal_fn') && dd_trace_internal_fn('ddtrace_reload_config');

require_once __DIR__ . '/functions.php';

if (!dd_tracing_enabled()) {
    dd_trace_disable_in_request();
    return;
}

dd_wrap_autoloader();
