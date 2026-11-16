--TEST--
Test baggage header interaction when is configured as first
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=baggage,tracecontext
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "x-datadog-trace-id" => 42,
            "x-datadog-parent-id" => 10,
            "x-datadog-origin" => "datadog",
            "x-datadog-sampling-priority" => 3,
            "traceparent" => "00-0000000000000000000000000000002a-0000000000000001-01",
            "tracestate" => "dd=p:00000000000000bb;p:00000000000000bb;s:1",
            "baggage" => "user.id=123,session.id=abc"
        ][$header] ?? null;
});
var_dump(DDTrace\generate_distributed_tracing_headers());

?>
--EXPECT--
array(3) {
  ["traceparent"]=>
  string(55) "00-0000000000000000000000000000002a-0000000000000001-01"
  ["tracestate"]=>
  string(29) "dd=p:00000000000000bb;t.dm:-0"
  ["baggage"]=>
  string(26) "user.id=123,session.id=abc"
}
