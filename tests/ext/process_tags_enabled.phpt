--TEST--
Process tags are added to root span when enabled
--ENV--
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
$span = \DDTrace\start_span();
$span->name = 'root_span';
$span->service = 'test_service';
\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();

// Check if process tags are present
if (isset($spans[0]['meta']['_dd.tags.process'])) {
    $processTags = $spans[0]['meta']['_dd.tags.process'];
    echo "Process tags present: YES\n";
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
    
    // Verify all values are normalized (lowercase, a-z0-9/.-_ only)
    $allNormalized = true;
    foreach ($tags as $tag) {
        $value = explode(':', $tag, 2)[1];
        if (!preg_match('/^[a-z0-9\/\.\-_]+$/', $value)) {
            $allNormalized = false;
            echo "Non-normalized value found: $value\n";
        }
    }
    echo "Values normalized: " . ($allNormalized ? 'YES' : 'NO') . "\n";
} else {
    echo "Process tags present: NO\n";
}
?>
--EXPECTF--
Process tags present: YES
Process tags: entrypoint.basedir:ext,entrypoint.name:process_tags_enabled.php,entrypoint.type:cli,entrypoint.workdir:%s
Keys sorted: YES
Values normalized: YES
