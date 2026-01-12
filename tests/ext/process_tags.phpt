--TEST--
Process tags are added to root span when enabled
--ENV--
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
$parent_span = \DDTrace\start_span();
$parent_span->name = 'root_span';
$parent_span->service = 'test_service';

$child_span = \DDTrace\start_span();
$child_span->name = 'root_span';
$child_span->service = 'test_service';

\DDTrace\close_span();
\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();

// Check if process tags are present
if (isset($spans[0]['meta']['_dd.process_tags'])) {
    $processTags = $spans[0]['meta']['_dd.process_tags'];
    echo "Process tags present in root span: YES\n";
    echo "Process tags: $processTags\n";

    // Verify format: comma-separated key:value pairs
    $tags = explode(',', $processTags);

    // Verify keys are sorted alphabetically
    $keys = array_map(function($tag) {
        return explode(':', $tag, 2)[0];
    }, $tags);
    $sortedKeys = $keys;
    sort($sortedKeys);
    echo "Keys sorted: " . ($keys === $sortedKeys ? 'YES' : 'NO') . "\n";
} else {
    echo "Process tags present in root span: NO\n";
}

if (isset($spans[1]['meta']['_dd.process_tags'])) {
    echo "Process tags present in child span: YES\n";
} else {
    echo "Process tags present in child span: NO\n";
}
?>
--EXPECTF--
Process tags present in root span: YES
Process tags: entrypoint.basedir:ext,entrypoint.name:process_tags,entrypoint.type:script,entrypoint.workdir:%s,runtime.sapi:cli
Keys sorted: YES
Process tags present in child span: NO
