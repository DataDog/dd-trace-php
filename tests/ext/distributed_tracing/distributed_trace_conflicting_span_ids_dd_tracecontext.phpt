--TEST--
Test consume_distributed_tracing_headers() with conflicting span_ids in datadog and tracecontext headers
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=datadog,tracecontext
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers([
    "x-datadog-trace-id" => 42,
    "x-datadog-parent-id" => 10,
    "traceparent" => "00-0000000000000000000000000000002a-0000000000000001-01",
]);

$span = \DDTrace\start_span();
echo "span.trace_id = {$span->traceId}\n";
echo "span.parent_id = {$span->parentId}\n";
echo "span.meta[_dd.parent_id] = {$span->meta["_dd.parent_id"]}\n";

?>
--EXPECT--
span.trace_id = 0000000000000000000000000000002a
span.parent_id = 1
span.meta[_dd.parent_id] = 000000000000000a
