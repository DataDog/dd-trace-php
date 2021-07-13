--TEST--
Distributed tracing headers propagate with curl_exec()
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--INI--
ddtrace.request_init_hook={PWD}/distributed_tracing_curl_inject.inc
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
HTTP_X_DATADOG_ORIGIN=phpt-test
--FILE--
<?php
DDTrace\trace_function('curl_exec', function (\DDTrace\SpanData $span) {
    $span->name = 'curl_exec';
});

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

include 'distributed_tracing.inc';
$headers = dt_decode_headers_from_httpbin($response);
dt_dump_headers_from_httpbin($headers, [
    'x-datadog-parent-id',
    'x-datadog-origin',
]);

$spans = dd_trace_serialize_closed_spans();
var_dump($headers['x-datadog-parent-id'] === (string) $spans[0]['span_id']);

echo 'Done.' . PHP_EOL;

if (PHP_VERSION_ID < 80000) {
    echo "No finished traces to be sent to the agent", PHP_EOL;
}

?>
--EXPECTF--
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
bool(true)
Done.
No finished traces to be sent to the agent
