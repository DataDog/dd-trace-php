--TEST--
Process tags are not added to root span when disabled
--DESCRIPTION--
Verifies that process tags are NOT added to the root span metadata
when DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED is set to false (default)
--ENV--
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=0
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
    echo "Process tags found: YES (unexpected)\n";
    echo "Process tags value: " . $spans[0]['meta']['_dd.tags.process'] . "\n";
} else {
    echo "Process tags found: NO (expected)\n";
}

// Verify other meta tags still work
echo "Service set: " . (isset($spans[0]['service']) && $spans[0]['service'] === 'test_service' ? 'YES' : 'NO') . "\n";
?>
--EXPECT--
Process tags found: NO (expected)
Service set: YES
