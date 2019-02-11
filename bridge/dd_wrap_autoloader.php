<?php

namespace DDTrace\Bridge;

require_once __DIR__ . '/functions.php';

if (php_sapi_name() == 'cli' && getenv('APP_ENV') != 'dd_testing') {
    return;
}

if (!dd_tracing_enabled() || !dd_tracing_route_enabled()) {
    return;
}

\DDTrace\Bridge\dd_wrap_autoloader();
