--TEST--
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT=ignore drops all incoming context including baggage
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT=ignore
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers([
    "x-datadog-trace-id" => 42,
    "x-datadog-parent-id" => 10,
    "x-datadog-sampling-priority" => 2,
    "x-datadog-tags" => "_dd.p.dm=-4",
    "baggage" => "user.id=123",
]);

$span = DDTrace\start_span();
$root = DDTrace\root_span();

// fresh trace: not from upstream
echo "same_as_upstream: " . ($root->traceId === "0000000000000000000000000000002a" ? "yes" : "no") . "\n";
echo "parent_id: " . ($root->parentId ?? 0) . "\n";

// no span link (context discarded entirely)
echo "links_count: " . count($root->links) . "\n";

// baggage dropped
$headers = DDTrace\generate_distributed_tracing_headers(['baggage']);
echo "baggage: " . ($headers['baggage'] ?? 'none') . "\n";

DDTrace\close_span();
?>
--EXPECT--
same_as_upstream: no
parent_id: 0
links_count: 0
baggage: none
