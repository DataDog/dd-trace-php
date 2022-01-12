<?php

namespace DDTrace\Bridge;

use DDTrace\Bootstrap;
use DDTrace\Integrations\IntegrationsLoader;

if (\PHP_VERSION_ID < 70000) {
    \date_default_timezone_set(@\date_default_timezone_get());
}

// Wildcard tracing closure for auto instrumentation
\DDTrace\trace_function('*', function ($s) {
    $s->resource = '__dd_auto_instrumented';
    $s->meta['foo'] = '__dd_auto_instrumented';
});

// Required classes and functions
require __DIR__ . '/autoload.php';

// Optional classes and functions
require __DIR__ . '/dd_register_optional_deps_autoloader.php';

Bootstrap::tracerOnce();
IntegrationsLoader::load();
