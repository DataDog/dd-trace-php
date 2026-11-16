--TEST--
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT=continue inherits upstream context
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT=continue
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers([
    "x-datadog-trace-id" => 42,
    "x-datadog-parent-id" => 10,
    "x-datadog-sampling-priority" => 1,
    "baggage" => "user.id=123",
]);

$span = DDTrace\start_span();
$root = DDTrace\root_span();

echo "trace_id: " . $root->traceId . "\n";
echo "parent_id: " . $root->parentId . "\n";
echo "links_count: " . count($root->links) . "\n";

$headers = DDTrace\generate_distributed_tracing_headers(['baggage']);
echo "baggage: " . ($headers['baggage'] ?? 'none') . "\n";

DDTrace\close_span();
?>
--EXPECT--
trace_id: 0000000000000000000000000000002a
parent_id: 10
links_count: 0
baggage: user.id=123
