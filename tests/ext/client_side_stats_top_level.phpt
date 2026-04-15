--TEST--
Client-side stats: _dd.top_level metric is set on top-level spans
--ENV--
DD_TRACE_STATS_COMPUTATION_ENABLED=true
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

$root = \DDTrace\start_trace_span();
$root->name = "root";
$root->service = "my-service";

$child_same = \DDTrace\start_span();
$child_same->name = "child_same_service";
$child_same->service = "my-service";

$grandchild_diff = \DDTrace\start_span();
$grandchild_diff->name = "grandchild_diff_service";
$grandchild_diff->service = "other-service";
\DDTrace\close_span();

\DDTrace\close_span();

$child_diff = \DDTrace\start_span();
$child_diff->name = "child_diff_service";
$child_diff->service = "other-service";
\DDTrace\close_span();

\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();

foreach ($spans as $span) {
    $has_top_level = isset($span["metrics"]["_dd.top_level"]);
    echo $span["name"] . ": _dd.top_level=" . ($has_top_level ? "1" : "not set") . "\n";
}

?>
--EXPECT--
root: _dd.top_level=1
child_diff_service: _dd.top_level=1
child_same_service: _dd.top_level=not set
grandchild_diff_service: _dd.top_level=1
