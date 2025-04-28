--TEST--
Client span with configured error status code (basic functionality)
--ENV--
DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=400-499
--FILE--
<?php
$span = \DDTrace\start_span();
$span->meta['http.status_code'] = 404;
$span->meta['span.kind'] = 'client';
\DDTrace\close_span();

$serialized = dd_trace_serialize_closed_spans();
var_dump($serialized[0]['error'] ?? null);
var_dump($serialized[0]['meta']['error.type'] ?? null);
var_dump($serialized[0]['meta']['error.msg'] ?? null);
?>
--EXPECT--
int(1)
string(10) "http_error"
string(14) "HTTP 404 Error"