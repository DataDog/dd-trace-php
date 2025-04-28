--TEST--
Explicit error flag takes precedence over status code configuration
--ENV--
DD_TRACE_HTTP_SERVER_ERROR_STATUSES=500-599
--FILE--
<?php
// Should NOT be error due to explicit flag = 0, even though status code is 500
$span1 = \DDTrace\start_span();
$span1->meta['http.status_code'] = 500;
$span1->meta['error'] = 0;
$span1->meta['debug_id'] = 'EXPLICIT_NO_ERROR';
\DDTrace\close_span();

// Should be error due to explicit flag = 1, even though status code is 200
$span2 = \DDTrace\start_span();
$span2->meta['http.status_code'] = 200;
$span2->meta['error'] = 1;
$span2->meta['debug_id'] = 'EXPLICIT_ERROR';
\DDTrace\close_span();

$serialized = dd_trace_serialize_closed_spans();

// Find spans by debug ID
$no_error_span = null;
$error_span = null;
foreach ($serialized as $span) {
    if (isset($span['meta']['debug_id'])) {
        if ($span['meta']['debug_id'] === 'EXPLICIT_NO_ERROR') {
            $no_error_span = $span;
        } elseif ($span['meta']['debug_id'] === 'EXPLICIT_ERROR') {
            $error_span = $span;
        }
    }
}

echo "Explicit error=0 with 500 status: ";
var_dump(isset($no_error_span['error']) ? $no_error_span['error'] : null);

echo "Explicit error=1 with 200 status: ";
var_dump(isset($error_span['error']) ? $error_span['error'] : null);
?>
--EXPECT--
Explicit error=0 with 500 status: NULL
Explicit error=1 with 200 status: int(1)