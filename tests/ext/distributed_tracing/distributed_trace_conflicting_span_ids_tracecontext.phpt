--TEST--
Test consume_distributed_tracing_headers() with conflicting span_ids in tracestate and traceparent headers
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=tracecontext
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers([
    "traceparent" => "00-0000000000000000000000000000002a-0000000000000001-01",
    "tracestate" => "dd=p:00000000000000bb;p:00000000000000bb;s:1",
]);

$span = \DDTrace\start_span();
echo "span.trace_id = {$span->traceId}\n";
echo "span.parent_id = {$span->parentId}\n";
echo "span.meta[_dd.parent_id] = {$span->meta["_dd.parent_id"]}\n";

?>
--EXPECT--
span.trace_id = 0000000000000000000000000000002a
span.parent_id = 1
span.meta[_dd.parent_id] = 00000000000000bb
