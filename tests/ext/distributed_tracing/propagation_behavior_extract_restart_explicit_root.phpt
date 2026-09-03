--TEST--
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT=restart attaches span link when the root is opened via DDTrace\start_trace_span()
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT=restart
DD_TRACE_PROPAGATION_STYLE_EXTRACT=datadog,tracecontext,baggage
DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED=0
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers([
    "x-datadog-trace-id" => 42,
    "x-datadog-parent-id" => 10,
    "x-datadog-sampling-priority" => 1,
    "traceparent" => "00-0000000000000000000000000000002a-000000000000000a-01",
    "baggage" => "user.id=123",
]);

// DDTrace\start_trace_span() switches to a fresh root stack before opening
// the span, unlike DDTrace\start_span(); the queued span link must still
// attach to this explicit root.
$span = DDTrace\start_trace_span();
$root = DDTrace\root_span();

echo "same_as_upstream: " . ($root->traceId === "0000000000000000000000000000002a" ? "yes" : "no") . "\n";
echo "links_count: " . count($root->links) . "\n";

$link = $root->links[0] ?? null;
if ($link !== null) {
    echo "link_trace_id: " . $link->traceId . "\n";
    echo "link_span_id: " . $link->spanId . "\n";
    echo "link_reason: " . ($link->attributes['reason'] ?? 'missing') . "\n";
}

DDTrace\close_span();
?>
--EXPECT--
same_as_upstream: no
links_count: 1
link_trace_id: 0000000000000000000000000000002a
link_span_id: 000000000000000a
link_reason: propagation_behavior_extract
