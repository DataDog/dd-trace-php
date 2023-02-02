--TEST--
Test 128 bit generation
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

ini_set("datadog.trace.128_bit_traceid_generation_enabled", "1");
DDTrace\start_span();
var_dump(\DDTrace\trace_id() > 2 ** 64);

ini_set("datadog.trace.128_bit_traceid_generation_enabled", "0");
DDTrace\set_distributed_tracing_context(0, 0);
var_dump(\DDTrace\trace_id() < 2 ** 64);

ini_set("datadog.trace.128_bit_traceid_generation_enabled", "1");
DDTrace\set_distributed_tracing_context(0, 0);
var_dump(\DDTrace\trace_id() > 2 ** 64);
$first_trace_id = \DDTrace\trace_id();
DDTrace\close_span();

ini_set("datadog.trace.128_bit_traceid_generation_enabled", "0");
DDTrace\start_span();
var_dump(\DDTrace\trace_id() < 2 ** 64);
DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();
var_dump(!isset($spans[0]["meta"]["_dd.p.tid"]));
var_dump(hexdec($spans[1]["meta"]["_dd.p.tid"]) == floor($spans[1]["start"] / 1000000000) * (1 << 32));

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
