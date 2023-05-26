--TEST--
Test 128-bit trace id retrieval
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\start_span();

var_dump(\DDTrace\trace_id_128());

ini_set("datadog.trace.128_bit_traceid_logging_enabled", "1");
var_dump(\DDTrace\trace_id_128());

var_dump(\DDTrace\set_distributed_tracing_context("33475823097097752842117799874953798269", "42"));
// 33475823097097752842117799874953798269 -> 192F3581C8461C79 ABF2684EE31CE27D
var_dump(\DDTrace\trace_id_128());

ini_set("datadog.trace.128_bit_traceid_logging_enabled", "0");
var_dump(\DDTrace\trace_id_128());

?>
--EXPECT--
string(20) "13930160852258120406"
string(20) "13930160852258120406"
string(32) "192f3581c8461c79abf2684ee31ce27d"
string(20) "12390080212876714621"
