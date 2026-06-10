--TEST--
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT falls back to OTEL_EXPORTER_OTLP_ENDPOINT + /v1/traces
--SKIPIF--
<?php if (!extension_loaded('ddtrace')) die('skip: ddtrace extension required'); ?>
--ENV--
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector.example:4318/
--FILE--
<?php

// No traces-specific endpoint set: it is derived from the base OTLP endpoint
// with the trailing slash stripped and /v1/traces appended.
var_dump(ini_get("OTEL_EXPORTER_OTLP_TRACES_ENDPOINT"));

?>
--EXPECT--
string(39) "http://collector.example:4318/v1/traces"
