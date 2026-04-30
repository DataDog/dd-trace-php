--TEST--
Sidecar connection in subprocess mode
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: PHP 7.0+ required'); ?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
--FILE--
<?php

// Verify the config is set correctly
echo "Connection mode: " . ini_get('datadog.trace.sidecar_connection_mode') . "\n";

// Create a span to trigger sidecar initialization
DDTrace\start_span();
DDTrace\close_span();

echo "Span created successfully\n";

?>
--EXPECT--
Connection mode: subprocess
Span created successfully
