--TEST--
DD_TRACE_AGENT_PROTOCOL_VERSION disables OTLP trace export even with OTEL_TRACES_EXPORTER=otlp
--SKIPIF--
<?php if (!extension_loaded('ddtrace')) die('skip: ddtrace extension required'); ?>
--ENV--
OTEL_TRACES_EXPORTER=otlp
DD_TRACE_AGENT_PROTOCOL_VERSION=0.4
--FILE--
<?php

// OTLP trace export is gated off when the agent trace protocol version is pinned.
var_dump(ini_get("datadog.trace.otlp_enabled"));
// Tracing itself remains enabled.
var_dump(ini_get("datadog.trace.enabled"));

?>
--EXPECT--
string(1) "0"
string(1) "1"
