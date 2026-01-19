--TEST--
Fork with thread mode configuration (thread mode not active in CLI)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70000) die('skip: PHP 7.0+ required');
if (PHP_OS_FAMILY === 'Windows') die('skip: pcntl_fork not available on Windows');
if (!function_exists('pcntl_fork')) die('skip: pcntl extension required');
?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
--FILE--
<?php

// Verify thread mode is configured
echo "Connection mode: " . ini_get('datadog.trace.sidecar_connection_mode') . "\n";

// Note: In CLI, thread mode doesn't successfully connect, so the fork warning
// won't trigger. The fork warning only appears when thread mode is actually active,
// which requires a PHP-FPM master/worker setup.

$pid = pcntl_fork();
if ($pid === 0) {
    // Child process
    echo "Child process\n";
    exit(0);
} else {
    // Parent process
    pcntl_wait($status);
    echo "Parent process\n";
}

?>
--EXPECTF--
Connection mode: thread
Child process
Parent process
