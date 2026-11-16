--TEST--
Test 128 bit generation
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

ini_set("datadog.trace.128_bit_traceid_generation_enabled", "1");
DDTrace\start_span();
var_dump(substr(\DDTrace\root_span()->traceId, 0, 16) != "0000000000000000");

ini_set("datadog.trace.128_bit_traceid_generation_enabled", "0");
DDTrace\set_distributed_tracing_context(0, 0);
var_dump(substr(\DDTrace\root_span()->traceId, 0, 16) == "0000000000000000");

ini_set("datadog.trace.128_bit_traceid_generation_enabled", "1");
DDTrace\set_distributed_tracing_context(0, 0);
var_dump(substr(\DDTrace\root_span()->traceId, 0, 16) != "0000000000000000");
$first_trace_id = \DDTrace\root_span()->traceId;
DDTrace\close_span();

ini_set("datadog.trace.128_bit_traceid_generation_enabled", "0");
DDTrace\start_span();
var_dump(substr(\DDTrace\root_span()->traceId, 0, 16) == "0000000000000000");
DDTrace\close_span();

$chunks = dd_trace_serialize_closed_spans()['chunks'];
var_dump(substr($chunks[0]["trace_id"], 0, 16) == "0000000000000000");
var_dump(hexdec(substr($chunks[1]["trace_id"], 0, 16)) == floor($chunks[1]["spans"][0]["start"] / 1000000000) * (1 << 32));

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
