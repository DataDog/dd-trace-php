--TEST--
OTEL_TRACES_EXPORTER=none disables tracing and leaves OTLP trace export off
--SKIPIF--
<?php if (!extension_loaded('ddtrace')) die('skip: ddtrace extension required'); ?>
--ENV--
OTEL_TRACES_EXPORTER=none
--FILE--
<?php

// "none" keeps OTLP trace export disabled.
var_dump(ini_get("datadog.trace.otlp_enabled"));
// "none" disables tracing entirely (pre-existing behavior).
var_dump(ini_get("datadog.trace.enabled"));

?>
--EXPECT--
string(1) "0"
string(1) "0"
