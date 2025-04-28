--TEST--
Exception fallback when no configuration is set
--ENV--
DD_TRACE_HTTP_SERVER_ERROR_STATUSES=418,500-599
--FILE--
<?php
// Should be error due to exception, despite 200 status code
$span1 = \DDTrace\start_span();
$span1->meta['http.status_code'] = 200;
try {
    throw new Exception("Test exception");
} catch (Exception $e) {
    $span1->exception = $e;
}
$span1->meta['debug_id'] = 'EXCEPTION_SPAN';
\DDTrace\close_span();

// Should NOT be error - 404 status code but no configuration
$span2 = \DDTrace\start_span();
$span2->meta['http.status_code'] = 404;
$span2->meta['debug_id'] = 'NO_EXCEPTION_SPAN';
\DDTrace\close_span();

$serialized = dd_trace_serialize_closed_spans();

// Find spans by debug ID
$exception_span = null;
$no_exception_span = null;
foreach ($serialized as $span) {
    if (isset($span['meta']['debug_id'])) {
        if ($span['meta']['debug_id'] === 'EXCEPTION_SPAN') {
            $exception_span = $span;
        } elseif ($span['meta']['debug_id'] === 'NO_EXCEPTION_SPAN') {
            $no_exception_span = $span;
        }
    }
}

echo "200 status with exception: ";
var_dump(isset($exception_span['error']) ? $exception_span['error'] : null);

echo "404 status without exception or config: ";
var_dump(isset($no_exception_span['error']) ? $no_exception_span['error'] : null);
?>
--EXPECT--
200 status with exception: int(1)
404 status without exception or config: NULL