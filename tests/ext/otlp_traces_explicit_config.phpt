--TEST--
OTLP traces endpoint/headers/timeout/protocol use explicit values and fallbacks
--SKIPIF--
<?php if (!extension_loaded('ddtrace')) die('skip: ddtrace extension required'); ?>
--ENV--
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://traces.example:4318/v1/traces
OTEL_EXPORTER_OTLP_HEADERS=api-key=secret,team=apm
OTEL_EXPORTER_OTLP_TIMEOUT=2500
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
--FILE--
<?php

// Explicit traces endpoint used as-is.
var_dump(ini_get("OTEL_EXPORTER_OTLP_TRACES_ENDPOINT"));
// Headers fall back to OTEL_EXPORTER_OTLP_HEADERS.
var_dump(ini_get("OTEL_EXPORTER_OTLP_TRACES_HEADERS"));
// Timeout falls back to OTEL_EXPORTER_OTLP_TIMEOUT (ms).
var_dump(ini_get("OTEL_EXPORTER_OTLP_TRACES_TIMEOUT"));
// Protocol falls back to OTEL_EXPORTER_OTLP_PROTOCOL.
var_dump(ini_get("OTEL_EXPORTER_OTLP_TRACES_PROTOCOL"));

?>
--EXPECT--
string(36) "http://traces.example:4318/v1/traces"
string(23) "api-key=secret,team=apm"
string(4) "2500"
string(9) "http/json"
