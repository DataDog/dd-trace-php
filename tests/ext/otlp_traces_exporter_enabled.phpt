--TEST--
OTEL_TRACES_EXPORTER=otlp enables OTLP trace export without disabling tracing
--SKIPIF--
<?php if (!extension_loaded('ddtrace')) die('skip: ddtrace extension required'); ?>
--ENV--
OTEL_TRACES_EXPORTER=otlp
--FILE--
<?php

// OTLP trace export enabled.
var_dump(ini_get("datadog.trace.otlp_enabled"));
// Tracing itself stays enabled (OTLP is a transport choice, not a disable).
var_dump(ini_get("datadog.trace.enabled"));

?>
--EXPECT--
string(1) "1"
string(1) "1"
