--TEST--
Client and server spans use their respective configurations
--ENV--
DD_TRACE_HTTP_SERVER_ERROR_STATUSES=500-599
DD_TRACE_HTTP_CLIENT_ERROR_STATUSES=400-499
--FILE--
<?php
// 1. Server span with 404 - should NOT be error
echo "=== SERVER SPAN WITH 404 ===\n";
$span_server = \DDTrace\start_span();
$span_server->meta['http.status_code'] = 404;
$span_server->meta['debug_id'] = 'SERVER_SPAN';
\DDTrace\close_span();

// 2. Client span with 404 - should be error
echo "=== CLIENT SPAN WITH 404 ===\n";
$span_client = \DDTrace\start_span();
$span_client->meta['http.status_code'] = 404;
$span_client->meta['span.kind'] = 'client';
$span_client->meta['debug_id'] = 'CLIENT_SPAN';
\DDTrace\close_span();

// Get serialized spans
$serialized = dd_trace_serialize_closed_spans();

// Check results
echo "=== RESULTS ===\n";
// Find spans by debug ID
$client_span = null;
$server_span = null;
foreach ($serialized as $span) {
    if (isset($span['meta']['debug_id'])) {
        if ($span['meta']['debug_id'] === 'CLIENT_SPAN') {
            $client_span = $span;
        } elseif ($span['meta']['debug_id'] === 'SERVER_SPAN') {
            $server_span = $span;
        }
    }
}

var_dump("Client 404 error:", isset($client_span['error']) ? $client_span['error'] : null);
var_dump("Server 404 error:", isset($server_span['error']) ? $server_span['error'] : null);
?>
--EXPECTF--
=== SERVER SPAN WITH 404 ===
=== CLIENT SPAN WITH 404 ===
=== RESULTS ===
string(17) "Client 404 error:"
int(1)
string(17) "Server 404 error:"
NULL