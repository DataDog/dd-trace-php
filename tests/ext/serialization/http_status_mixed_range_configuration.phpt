--TEST--
Mixed range configuration with specific status codes
--ENV--
DD_TRACE_HTTP_SERVER_ERROR_STATUSES=400-403,419,500-503
--FILE--
<?php
// Create spans with different status codes
$status_codes = [400, 403, 404, 419, 500, 503, 504];

foreach ($status_codes as $code) {
    $span = \DDTrace\start_span();
    $span->meta['http.status_code'] = $code;
    $span->meta['debug_id'] = "STATUS_$code";
    \DDTrace\close_span();
}

$serialized = dd_trace_serialize_closed_spans();

// Check results for each status code
echo "=== RESULTS ===\n";
foreach ($status_codes as $code) {
    $span = null;
    foreach ($serialized as $s) {
        if (isset($s['meta']['debug_id']) && $s['meta']['debug_id'] === "STATUS_$code") {
            $span = $s;
            break;
        }
    }

    echo "Status $code is error: " . (isset($span['error']) ? 'YES' : 'NO') . "\n";
}
?>
--EXPECT--
=== RESULTS ===
Status 400 is error: YES
Status 403 is error: YES
Status 404 is error: NO
Status 419 is error: YES
Status 500 is error: YES
Status 503 is error: YES
Status 504 is error: NO