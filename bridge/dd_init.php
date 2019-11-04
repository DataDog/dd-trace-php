<?php

namespace DDTrace\Bridge;

use DDTrace\Bootstrap;
use DDTrace\Integrations\IntegrationsLoader;

/* Do not use require_once here. On PHP 5.6 with xdebug enabled, the
 * require_once will segfault if DD_TRACE_NO_AUTOLOADER=1 is set.
 * This has to do with the cyclic dependency between these two files, and for
 * whatever reason the require_once fails under certain circumstances.
 */
if (!function_exists('dd_tracing_enabled')) {
    require __DIR__ . '/functions.php';
}

// trigger configuration reload to memoize values of all configuration options as set by environment variables
function_exists('dd_trace_internal_fn') && dd_trace_internal_fn('ddtrace_reload_config');
if (!dd_tracing_enabled()) {
    return;
}

Bootstrap::tracerOnce();
IntegrationsLoader::load();
