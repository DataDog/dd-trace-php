--TEST--
Test 128-bit trace id retrieval
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\start_span();

var_dump(\DDTrace\logs_correlation_trace_id());

ini_set("datadog.trace.128_bit_traceid_logging_enabled", "1");
var_dump(\DDTrace\logs_correlation_trace_id());

$newTrace = \DDTrace\start_trace_span();
var_dump(\DDTrace\logs_correlation_trace_id());
\DDTrace\close_span();

\DDTrace\set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
// 33475823097097752842117799874953798269 -> 192F3581C8461C79 ABF2684EE31CE27D
var_dump(\DDTrace\logs_correlation_trace_id());

$newTrace = \DDTrace\start_trace_span();
var_dump(\DDTrace\logs_correlation_trace_id());
\DDTrace\close_span();

ini_set("datadog.trace.128_bit_traceid_logging_enabled", "0");
var_dump(\DDTrace\logs_correlation_trace_id());



\DDTrace\set_distributed_tracing_context("18446744073709551617", "42"); // 2^64 + 1
var_dump(\DDTrace\logs_correlation_trace_id());

ini_set("datadog.trace.128_bit_traceid_logging_enabled", "1");
var_dump(\DDTrace\logs_correlation_trace_id()); // 2^64 + 1 -> 1 0000000000000001



ini_set("datadog.trace.128_bit_traceid_logging_enabled", "0");
\DDTrace\set_distributed_tracing_context("18446744073709551615", "42"); // 2^64 - 1
var_dump(\DDTrace\logs_correlation_trace_id());

ini_set("datadog.trace.128_bit_traceid_logging_enabled", "1");
var_dump(\DDTrace\logs_correlation_trace_id()); // 2^64 - 1



ini_set("datadog.trace.128_bit_traceid_logging_enabled", "0");
\DDTrace\set_distributed_tracing_context("18446744073709551616", "42"); // 2^64
var_dump(\DDTrace\logs_correlation_trace_id());

ini_set("datadog.trace.128_bit_traceid_logging_enabled", "1"); // 2^64 -> 1 0000000000000000
var_dump(\DDTrace\logs_correlation_trace_id());

?>
--EXPECTF--
string(32) "%sc151df7d6ee5e2d6"
string(32) "%sc151df7d6ee5e2d6"
string(32) "%sa3978fb9b92502a8"
string(32) "192f3581c8461c79abf2684ee31ce27d"
string(32) "%sc08c967f0e5e7b0a"
string(20) "12390080212876714621"
string(1) "1"
string(32) "00000000000000010000000000000001"
string(20) "18446744073709551615"
string(20) "18446744073709551615"
string(1) "0"
string(32) "00000000000000010000000000000000"
