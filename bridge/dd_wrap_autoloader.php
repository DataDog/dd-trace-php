<?php

namespace DDTrace\Bridge;

require_once __DIR__ . '/functions.php';

if (php_sapi_name() == 'cli' && getenv('APP_ENV') != 'dd_testing') {
    dd_trace_disable_in_request();
    return;
}

if (!dd_tracing_enabled()) {
    dd_trace_disable_in_request();
    return;
}

dd_wrap_autoloader();
