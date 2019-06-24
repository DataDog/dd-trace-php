<?php

namespace DDTrace\Bridge;

use DDTrace\Bootstrap;
use DDTrace\Integrations\IntegrationsLoader;

require_once __DIR__ . '/functions.php';

// trigger configuration reload to memoize values of all configuration options as set by environment variables
function_exists('dd_trace_internal_fn') && dd_trace_internal_fn('ddtrace_reload_config');
if (!dd_tracing_enabled()) {
    return;
}

Bootstrap::tracerOnce();
IntegrationsLoader::load();
