--TEST--
Invalid sidecar connection mode falls back to auto
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: PHP 7.0+ required'); ?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=invalid_mode
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
--FILE--
<?php

// Invalid mode should fall back to auto (default)
echo "Connection mode: " . ini_get('datadog.trace.sidecar_connection_mode') . "\n";

// Create a span to verify sidecar still works
DDTrace\start_span();
DDTrace\close_span();

echo "Span created successfully\n";

?>
--EXPECT--
Connection mode: auto
Span created successfully
