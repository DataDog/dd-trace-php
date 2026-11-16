--TEST--
Client-side stats: _dd.top_level compares a span with its nearest non-dropped ancestor
--ENV--
DD_TRACE_STATS_COMPUTATION_ENABLED=true
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

function inner($service) {}
function middle($service, $innerService) { inner($innerService); }

DDTrace\trace_function('inner', function (\DDTrace\SpanData $span, $args) {
    $span->name = "inner";
    $span->service = $args[0];
});
DDTrace\trace_function('middle', function (\DDTrace\SpanData $span, $args) {
    $span->service = $args[0];
    return false; // dropped; its child is kept and reparented to the root
});

$root = \DDTrace\start_trace_span();
$root->name = "root";
$root->service = "svc-a";
middle("svc-b", "svc-a"); // same service as the root, unlike its dropped parent
middle("svc-c", "svc-c"); // same service as its dropped parent, unlike the root
\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans()['chunks'][0]['spans'];
$rootId = array_column($spans, "span_id", "name")["root"];
$lines = [];
foreach ($spans as $span) {
    $lines[] = $span["name"] . " (" . $span["service"] . "): _dd.top_level=" . (isset($span["attributes"]["_dd.top_level"]) ? "1" : "not set")
        . (isset($span["parent_id"]) && $span["parent_id"] === $rootId ? ", child of root" : "");
}
sort($lines);
echo implode("\n", $lines), "\n";

?>
--EXPECT--
inner (svc-a): _dd.top_level=not set, child of root
inner (svc-c): _dd.top_level=1, child of root
root (svc-a): _dd.top_level=1
