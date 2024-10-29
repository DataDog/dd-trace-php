--TEST--
Create a child span from a span that has already finished
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=false
DD_TRACE_DEBUG=1
--FILE--
<?php

$parent = \DDTrace\start_trace_span();
$parent->name = "parent";
$headers = \DDTrace\generate_distributed_tracing_headers();
var_dump($headers);
\DDTrace\close_span();
\DDTrace\switch_stack($parent);

$traceIdHigh = substr($parent->traceId, 0, 16);
$distributedTracingHeaders = [
    "x-datadog-parent-id" => $parent->id,
    "x-datadog-trace-id" => $parent->id,
    "x-datadog-tags" => "_dd.p.tid=$traceIdHigh,_dd.p.dm=-0",
];
var_dump($distributedTracingHeaders);

\DDTrace\consume_distributed_tracing_headers($headers);
//\DDTrace\set_distributed_tracing_context($parent->traceId, $parent->id);
$childSpan = \DDTrace\start_span();
$childSpan->name = "child";
\DDTrace\close_span();

?>
--EXPECTF--
