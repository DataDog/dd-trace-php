--TEST--
CLI shutdown does not hang when agent is unreachable
--DESCRIPTION--
When DD_TRACE_CLI_ENABLED is true (default since v1.4.0) and the Datadog agent
is unreachable, the background writer thread may block on curl operations during
shutdown. Previously, ddtrace_coms_flush_shutdown_writer_synchronous() called
pthread_join() without a bounded timeout after pthread_cancel(), which could
cause the PHP CLI/cron process to hang indefinitely (see GitHub issue #2629).

This test verifies that the process exits cleanly within a reasonable time even
when the agent is completely unreachable.
--SKIPIF--
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There is no background sender on Windows'); ?>
<?php if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: can intentionally leak memory depending on timing"); ?>
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_AGENT_HOST=192.0.2.1
DD_TRACE_AGENT_PORT=18126
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_TRACE_SHUTDOWN_TIMEOUT=2000
DD_TRACE_AGENT_TIMEOUT=500
DD_TRACE_AGENT_CONNECT_TIMEOUT=500
DD_TRACE_AGENT_RETRIES=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_CRASHTRACKING_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
// Generate trace data so the background writer has work to flush on shutdown
DDTrace\start_span();
DDTrace\close_span();
echo "Done\n";
?>
--EXPECT--
Done
