--TEST--
_dd.sdk.otlp_export marker is set to "false" on the first span of each trace chunk only
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
for ($i = 0; $i < 2; $i++) {
    \DDTrace\start_trace_span()->name = "root_$i";
    \DDTrace\start_span()->name = "child_$i";
    \DDTrace\start_span()->name = "grandchild_$i";
    \DDTrace\close_span();
    \DDTrace\close_span();
    \DDTrace\close_span();
}

$seen = [];
foreach (dd_trace_serialize_closed_spans() as $span) {
    $first = !isset($seen[$span['trace_id']]);
    $seen[$span['trace_id']] = true;
    echo ($first ? "first" : "other"), ": ", var_export($span['meta']['_dd.sdk.otlp_export'] ?? null, true), "\n";
}
echo count($seen), " chunks\n";
?>
--EXPECT--
first: 'false'
other: NULL
other: NULL
first: 'false'
other: NULL
other: NULL
2 chunks
