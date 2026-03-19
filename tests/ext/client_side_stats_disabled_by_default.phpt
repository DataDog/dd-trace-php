--TEST--
Client-side stats: _dd.top_level metric is not set when stats disabled (default)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

$root = \DDTrace\start_trace_span();
$root->name = "root";
$root->service = "my-service";
\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();

foreach ($spans as $span) {
    $has_top_level = isset($span["metrics"]["_dd.top_level"]);
    echo $span["name"] . ": _dd.top_level=" . ($has_top_level ? "1" : "not set") . "\n";
}

?>
--EXPECT--
root: _dd.top_level=not set
