<?php

namespace DDTrace\Bridge;

require_once __DIR__ . '/functions.php';

if (php_sapi_name() == 'cli' && getenv('APP_ENV') != 'dd_testing') {
    return;
}

if (!dd_tracing_enabled()) {
    return;
}

dd_wrap_autoloader();
