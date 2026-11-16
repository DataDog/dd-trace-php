<?php

$handler = static function () {
    if (PHP_SAPI !== 'frankenphp' || !PHP_ZTS) {
        http_response_code(500);
        echo "Expected FrankenPHP with ZTS\n";
        return;
    }
    // The profiler and AppSec are part of ddtrace; the profiler's INI settings
    // and AppSec's functions exist only when they are bundled.
    if (!extension_loaded('ddtrace')) {
        http_response_code(500);
        echo "Missing extension: ddtrace\n";
        return;
    }
    if (!function_exists('datadog\appsec\is_enabled')) {
        http_response_code(500);
        echo "AppSec is not bundled\n";
        return;
    }
    if (!filter_var(ini_get('datadog.profiling.enabled'), FILTER_VALIDATE_BOOLEAN)) {
        http_response_code(500);
        echo "Profiling is disabled\n";
        return;
    }

    // Give the profiler time to collect samples before exercising its shutdown.
    usleep(100000);
    echo "ok\n";
};

if (getenv('DD_TEST_FRANKENPHP_MODE') === 'worker') {
    ignore_user_abort(true);
    while (frankenphp_handle_request($handler)) {
        gc_collect_cycles();
    }
} else {
    $handler();
}
