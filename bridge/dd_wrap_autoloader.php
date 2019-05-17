<?php

namespace DDTrace\Bridge;

if (PHP_VERSION_ID < 70000) {
    date_default_timezone_set(@date_default_timezone_get());
}

require_once __DIR__ . '/functions.php';

if (!dd_tracing_enabled()) {
    dd_trace_disable_in_request();
    return;
}

dd_wrap_autoloader();
