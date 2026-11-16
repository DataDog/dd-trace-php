--TEST--
Disabling CLI tracing after communications initialization preserves the writer lifecycle
--DESCRIPTION--
CLI tracing is disabled after communications initialization. Verify that no
root span is created, whereas the background writer still starts because
communications were initialized before CLI tracing was disabled. This
distinguishes CLI disable from excluded-module disable, which happens before
communications initialization and must not start a writer.
--SKIPIF--
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There is no background sender on Windows'); ?>
--INI--
datadog.trace.cli_enabled=0
datadog.trace.sidecar_trace_sender=0
datadog.instrumentation_telemetry_enabled=0
datadog.remote_config_enabled=0
--FILE--
<?php
var_dump(DDTrace\root_span());
var_dump(dd_trace_internal_fn('set_writer_send_on_flush', false));
?>
--EXPECT--
NULL
bool(true)
