<?php

namespace DDTrace\Bridge;

require_once __DIR__ . '/functions.php';

if (!dd_tracing_enabled()) {
    dd_trace_disable_in_request();
    return;
}

dd_wrap_autoloader();
