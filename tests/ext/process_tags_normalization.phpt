--TEST--
Process tags values are properly normalized
--DESCRIPTION--
Verifies that process tag values follow the normalization rules:
- Lowercase
- Only a-z, 0-9, /, ., - allowed
- Everything else replaced with _
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

// Check if process tags are present and normalized
if (isset($spans[0]['meta']['_dd.tags.process'])) {
    $processTags = $spans[0]['meta']['_dd.tags.process'];
    
    // Parse tags
    $tags = explode(',', $processTags);
    $allNormalized = true;
    
    foreach ($tags as $tag) {
        list($key, $value) = explode(':', $tag, 2);
        
        // Check if value is normalized (only lowercase, a-z, 0-9, /, ., -, _)
        if (!preg_match('/^[a-z0-9\/\.\-_]+$/', $value)) {
            echo "Value not normalized: $value\n";
            $allNormalized = false;
        }
        
        // Check if there are any uppercase letters
        if (preg_match('/[A-Z]/', $value)) {
            echo "Uppercase found in: $value\n";
            $allNormalized = false;
        }
    }
    
    echo "All values normalized: " . ($allNormalized ? 'YES' : 'NO') . "\n";
    
    // Verify the entrypoint.type is one of the expected values
    foreach ($tags as $tag) {
        if (strpos($tag, 'entrypoint.type:') === 0) {
            list($key, $type) = explode(':', $tag, 2);
            $validTypes = ['script', 'cli', 'executable'];
            if (in_array($type, $validTypes)) {
                echo "Entrypoint type valid: YES\n";
            } else {
                echo "Entrypoint type invalid: $type\n";
            }
        }
    }
} else {
    echo "Process tags not found\n";
}
?>
--EXPECTF--
All values normalized: YES
Entrypoint type valid: YES


