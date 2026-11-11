--TEST--
Shutdown flush releases the error returned by a broken sidecar connection
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_FORCE_FLUSH_ON_SHUTDOWN=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_LOG_LEVEL=off
--FILE--
<?php
// Finalization disables reconnection, just as it does during real shutdown.
dd_trace_internal_fn('finalize_telemetry');
var_dump(dd_trace_internal_fn('break_sidecar_connection'));

// Wait for the connection to break without sleeps. An error is not JSON stats.
$stats = dd_trace_internal_fn('stats_sidecar');
var_dump(is_string($stats) && json_decode($stats, true) === null);

// MSHUTDOWN now flushes the closed connection. ASAN/Valgrind must not report
// the error message returned by that flush as leaked memory.
?>
--EXPECT--
bool(true)
bool(true)
