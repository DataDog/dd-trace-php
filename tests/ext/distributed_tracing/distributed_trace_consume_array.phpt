--TEST--
Test consume_distributed_tracing_headers() with array argument
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers([
    "x-datadog-trace-id" => 42,
    "x-datadog-parent-id" => 10,
    "x-datadog-origin" => "datadog",
    "x-datadog-sampling-priority" => 3,
]);
var_dump(DDTrace\generate_distributed_tracing_headers(["tracecontext"]));

?>
--EXPECT--
array(2) {
  ["traceparent"]=>
  string(55) "00-0000000000000000000000000000002a-000000000000000a-01"
  ["tracestate"]=>
  string(24) "dd=o:datadog;s:3;t.dm:-0"
}
