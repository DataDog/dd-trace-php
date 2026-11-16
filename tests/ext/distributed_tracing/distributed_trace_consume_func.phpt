--TEST--
Test consume_distributed_tracing_headers() with function argument
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "x-datadog-trace-id" => 42,
            "x-datadog-parent-id" => 10,
            "x-datadog-origin" => "datadog",
            "x-datadog-sampling-priority" => 3,
            "baggage" => "user.id=123,session.id=abc"
        ][$header] ?? null;
});
var_dump(DDTrace\generate_distributed_tracing_headers());

?>
--EXPECT--
array(8) {
  ["x-datadog-sampling-priority"]=>
  string(1) "3"
  ["x-datadog-tags"]=>
  string(11) "_dd.p.dm=-0"
  ["x-datadog-origin"]=>
  string(7) "datadog"
  ["x-datadog-trace-id"]=>
  string(2) "42"
  ["x-datadog-parent-id"]=>
  string(2) "10"
  ["traceparent"]=>
  string(55) "00-0000000000000000000000000000002a-000000000000000a-01"
  ["tracestate"]=>
  string(24) "dd=o:datadog;s:3;t.dm:-0"
  ["baggage"]=>
  string(26) "user.id=123,session.id=abc"
}
