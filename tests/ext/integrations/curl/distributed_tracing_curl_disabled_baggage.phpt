--TEST--
Baggage headers should not be propagated when curl integration is disabled
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE_INJECT=B3,B3 single header,Datadog,tracecontext,baggage
DD_TRACE_CURL_ENABLED=false
DD_DISTRIBUTED_TRACING=false
--FILE--
<?php
include 'curl_helper.inc';

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port . '/headers';

// Set baggage before making the request
$span = DDTrace\start_span();
$span->baggage["userId"] = "Amélie";
$span->baggage["session"] = "xyz";

// Perform a curl request
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
show_curl_error_on_fail($ch);
curl_close($ch);

// Extract headers from the response
include 'distributed_tracing.inc';
$headers = dt_decode_headers_from_httpbin($response);
dt_dump_headers_from_httpbin($headers, [
    'baggage',  // The key we're testing for absence
]);

// Close the span
DDTrace\close_span();

// Assert that the baggage header was NOT propagated
var_dump(!isset($headers['baggage']));

echo 'Done.' . PHP_EOL;
?>
--EXPECT--
bool(true)
Done.
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://localhost:8126
