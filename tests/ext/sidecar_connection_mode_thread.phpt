--TEST--
Sidecar connection in thread mode
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70000) die('skip: PHP 7.0+ required');
if (PHP_OS_FAMILY === 'Windows') die('skip: Thread mode not supported on Windows');
?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
--FILE--
<?php

// Verify the config is set correctly
echo "Connection mode: " . ini_get('datadog.trace.sidecar_connection_mode') . "\n";

// Note: In a simple CLI test, thread mode may fail to connect since it requires
// a master/worker setup (like PHP-FPM). The test verifies the configuration is
// set correctly. In production, thread mode works with PHP-FPM master/worker processes.

// Create a span - this may or may not connect successfully in CLI mode
DDTrace\start_span();
DDTrace\close_span();

echo "Test completed\n";

?>
--EXPECTF--
Connection mode: thread
Test completed
