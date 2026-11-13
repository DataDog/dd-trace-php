--TEST--
Client-side stats: _dd.top_level compares the services as mapped by DD_SERVICE_MAPPING
--ENV--
DD_TRACE_STATS_COMPUTATION_ENABLED=true
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_SERVICE_MAPPING=svc-a:mapped,svc-b:mapped
--FILE--
<?php

$root = \DDTrace\start_trace_span();
$root->name = "root";
$root->service = "svc-a";

foreach (["child_mapped_alike" => "svc-b", "child_named_as_mapped" => "mapped", "child_unmapped" => "other"] as $name => $service) {
    $child = \DDTrace\start_span();
    $child->name = $name;
    $child->service = $service;
    \DDTrace\close_span();
}

\DDTrace\close_span();

$lines = [];
foreach (dd_trace_serialize_closed_spans()['chunks'][0]['spans'] as $span) {
    $lines[] = $span["name"] . " (" . $span["service"] . "): _dd.top_level=" . (isset($span["attributes"]["_dd.top_level"]) ? "1" : "not set");
}
sort($lines);
echo implode("\n", $lines), "\n";

?>
--EXPECT--
child_mapped_alike (mapped): _dd.top_level=not set
child_named_as_mapped (mapped): _dd.top_level=not set
child_unmapped (other): _dd.top_level=1
root (mapped): _dd.top_level=1
