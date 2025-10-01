--TEST--
Process tags are added to root span when enabled
--DESCRIPTION--
Verifies that process tags are properly added to the root span metadata
when DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED is set to true
--ENV--
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
use DDTrace\SpanData;

// Create a root span
$span = \DDTrace\start_span();
$span->name = 'root_span';
$span->service = 'test_service';

// Close the span
\DDTrace\close_span();

// Get the trace
$spans = dd_trace_serialize_closed_spans();

// Check if process tags are present
if (isset($spans[0]['meta']['_dd.tags.process'])) {
    $processTags = $spans[0]['meta']['_dd.tags.process'];
    echo "Process tags found: YES\n";
    
    // Parse and verify the format
    $tags = explode(',', $processTags);
    echo "Number of tags: " . count($tags) . "\n";
    
    // Verify format (key:value)
    $validFormat = true;
    $tagKeys = [];
    foreach ($tags as $tag) {
        if (strpos($tag, ':') === false) {
            $validFormat = false;
            break;
        }
        list($key, $value) = explode(':', $tag, 2);
        $tagKeys[] = $key;
        
        // Verify normalization: only lowercase, a-z, 0-9, /, ., -
        if (!preg_match('/^[a-z0-9\/\.\-_]+$/', $value)) {
            echo "Invalid normalized value: $value\n";
            $validFormat = false;
        }
    }
    
    echo "Valid format: " . ($validFormat ? 'YES' : 'NO') . "\n";
    
    // Verify keys are sorted
    $sortedKeys = $tagKeys;
    sort($sortedKeys);
    $isSorted = ($tagKeys === $sortedKeys);
    echo "Keys sorted: " . ($isSorted ? 'YES' : 'NO') . "\n";
    
    // Check for expected keys
    $expectedKeys = ['entrypoint.basedir', 'entrypoint.name', 'entrypoint.type', 'entrypoint.workdir'];
    $hasExpectedKeys = true;
    foreach ($expectedKeys as $expectedKey) {
        if (!in_array($expectedKey, $tagKeys)) {
            echo "Missing expected key: $expectedKey\n";
            $hasExpectedKeys = false;
        }
    }
    echo "Has expected keys: " . ($hasExpectedKeys ? 'YES' : 'NO') . "\n";
    
    // Display the tags for verification
    echo "Process tags value: $processTags\n";
} else {
    echo "Process tags found: NO\n";
}
?>
--EXPECTF--
Process tags found: YES
Number of tags: 4
Valid format: YES
Keys sorted: YES
Has expected keys: YES
Process tags value: entrypoint.basedir:%s,entrypoint.name:%s,entrypoint.type:%s,entrypoint.workdir:%s
