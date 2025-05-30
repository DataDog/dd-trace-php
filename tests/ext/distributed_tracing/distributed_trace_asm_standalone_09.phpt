--TEST--
_dd.p.ts is not manipulated when no asm event
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
HTTP_X_DATADOG_SAMPLING_PRIORITY=3
HTTP_X_DATADOG_TAGS=_dd.p.ts=FFF
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_APM_TRACING_ENABLED=0
--FILE--
<?php

$outer = DDTrace\start_span();
DDTrace\close_span();

$traces = dd_trace_serialize_closed_spans();

var_dump($traces[0]['meta']['_dd.p.ts']);

?>
--EXPECTF--
string(3) "FFF"
