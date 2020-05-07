<?php

namespace DDTrace\Bridge;

use DDTrace\Bootstrap;
use DDTrace\Integrations\IntegrationsLoader;

require_once __DIR__ . '/functions.php';

// trigger configuration reload to memoize values of all configuration options as set by environment variables
function_exists('dd_trace_internal_fn') && \dd_trace_internal_fn('ddtrace_reload_config');
if (!dd_tracing_enabled()) {
    \dd_trace_disable_in_request();
    return;
}

// Required classes and functions
require __DIR__ . '/autoload.php';
// Optional classes and functions
require __DIR__ . '/dd_optional_deps_autoloader.php';
spl_autoload_register(['\DDTrace\Bridge\OptionalDepsAutoloader', 'load'], true, true);

Bootstrap::tracerOnce();
IntegrationsLoader::load();
