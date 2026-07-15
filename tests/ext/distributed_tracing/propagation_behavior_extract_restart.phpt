--TEST--
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT=restart starts fresh trace with span link
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
    "x-datadog-origin" => "synthetics",
    "x-datadog-tags" => "_dd.p.foo=bar",
    "traceparent" => "00-0000000000000000000000000000002a-000000000000000a-01",
    "tracestate" => "dd=s:1;o:synthetics",
    "baggage" => "user.id=123",
]);

$span = DDTrace\start_span();
$root = DDTrace\root_span();

// fresh trace: different from upstream trace_id 42
echo "same_as_upstream: " . ($root->traceId === "0000000000000000000000000000002a" ? "yes" : "no") . "\n";
echo "root_has_origin: " . (isset($root->origin) ? "yes" : "no") . "\n";
echo "root_has_tracestate: " . (isset($root->tracestate) ? "yes" : "no") . "\n";

// span link attached to root span
echo "links_count: " . count($root->links) . "\n";

$link = $root->links[0] ?? null;
if ($link !== null) {
    // link captures upstream trace/span ids
    echo "link_trace_id: " . $link->traceId . "\n";
    echo "link_span_id: " . $link->spanId . "\n";
    echo "link_reason: " . ($link->attributes['reason'] ?? 'missing') . "\n";
    echo "link_context_headers: " . ($link->attributes['context_headers'] ?? 'missing') . "\n";
    // link attributes carry only reason/context_headers, nothing from the extracted meta tags
    echo "link_attributes_count: " . count($link->attributes) . "\n";
    echo "link_has_foo: " . (isset($link->attributes['_dd.p.foo']) ? "yes" : "no") . "\n";
}

$tid = $root->traceId;
echo "trace_id_valid: " . (preg_match('/^[0-9a-f]{32}$/', $tid) && $tid !== "00000000000000000000000000000000" ? "yes" : "no") . "\n";

// baggage preserved
$headers = DDTrace\generate_distributed_tracing_headers(['baggage']);
echo "baggage: " . ($headers['baggage'] ?? 'none') . "\n";

// upstream _dd.p.foo not in outbound tags
$dd_headers = DDTrace\generate_distributed_tracing_headers(['datadog']);
$tags = $dd_headers['x-datadog-tags'] ?? '';
echo "foo_in_tags: " . (strpos($tags, '_dd.p.foo') !== false ? "yes" : "no") . "\n";

DDTrace\close_span();
?>
--EXPECT--
same_as_upstream: no
root_has_origin: no
root_has_tracestate: no
links_count: 1
link_trace_id: 0000000000000000000000000000002a
link_span_id: 000000000000000a
link_reason: propagation_behavior_extract
link_context_headers: datadog
link_attributes_count: 2
link_has_foo: no
trace_id_valid: yes
baggage: user.id=123
foo_in_tags: no
