--TEST--
Test generate_distributed_tracing_headers()
--ENV--
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_SAMPLING_PRIORITY=2
HTTP_TRACEPARENT=00-0000000000000000000000000000002a-0000000000000001-01
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

var_dump(DDTrace\generate_distributed_tracing_headers());

?>
--EXPECT--
array(2) {
  ["traceparent"]=>
  string(55) "00-0000000000000000000000000000002a-0000000000000001-01"
  ["tracestate"]=>
  string(24) "dd=p:000000000000000a;s:2;t.dm:-0"
}