--TEST--
Process tags are added to the payload when enabled
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

$payload = dd_trace_serialize_closed_spans();

// Check if process tags are present
if (isset($payload['attributes']['_dd.tags.process'])) {
    $processTags = $payload['attributes']['_dd.tags.process'];
    echo "Process tags present in payload: YES\n";
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
    echo "Process tags present in payload: NO\n";
}

foreach ($payload['chunks'][0]['spans'] as $span) {
    echo "Process tags present in span: ", isset($span['attributes']['_dd.tags.process']) ? 'YES' : 'NO', "\n";
}
?>
--EXPECTF--
Process tags present in payload: YES
Process tags: entrypoint.basedir:ext,entrypoint.name:process_tags,entrypoint.type:script,entrypoint.workdir:%s,runtime.sapi:cli
Keys sorted: YES
Process tags present in span: NO
Process tags present in span: NO
