--TEST--
Test ts is only added to traces with ASM events
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
HTTP_X_DATADOG_SAMPLING_PRIORITY=3
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_APM_TRACING_ENABLED=0
--FILE--
<?php

$root1 = DDTrace\start_trace_span();
$root1->name = 'root1';
DDTrace\Testing\emit_asm_event();
DDTrace\close_span();
$root2 = DDTrace\start_trace_span();
$root2->name = 'root2';
DDTrace\Testing\emit_asm_event();
DDTrace\close_span();
$root3= DDTrace\start_trace_span();
$root3->name = 'root3';
DDTrace\close_span();

$traces = dd_trace_serialize_closed_spans();

var_dump($traces[0]['name']);
var_dump(isset($traces[0]['meta']['_dd.p.ts']));
var_dump($traces[1]['name']);
var_dump($traces[1]['meta']['_dd.p.ts']);
var_dump($traces[2]['name']);
var_dump($traces[2]['meta']['_dd.p.ts']);

?>
--EXPECTF--
string(5) "root3"
bool(false)
string(5) "root2"
string(2) "02"
string(5) "root1"
string(2) "02"