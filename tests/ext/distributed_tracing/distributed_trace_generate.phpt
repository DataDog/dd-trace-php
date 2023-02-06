--TEST--
Test generate_distributed_tracing_headers()
--ENV--
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
HTTP_X_DATADOG_SAMPLING_PRIORITY=3
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

var_dump(DDTrace\generate_distributed_tracing_headers());

?>
--EXPECT--
array(6) {
  ["x-datadog-sampling-priority"]=>
  string(1) "3"
  ["x-datadog-origin"]=>
  string(7) "datadog"
  ["x-datadog-trace-id"]=>
  string(2) "42"
  ["x-datadog-parent-id"]=>
  string(2) "10"
  ["traceparent"]=>
  string(55) "00-0000000000000000000000000000002a-000000000000000a-01"
  ["tracestate"]=>
  string(16) "dd=o:datadog;s:3"
}
