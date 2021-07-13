--TEST--
Distributed tracing headers propagate with existing headers set with curl_setopt()
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
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
]);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'x-my-custom-header: foo',
    'x-mas: tree',
]);

$responses = [];
$responses[] = curl_exec($ch);
$responses[] = curl_exec($ch);
curl_close($ch);

include 'distributed_tracing.inc';
foreach ($responses as $key => $response) {
    echo 'Response #' . $key . PHP_EOL;
    $headers = dt_decode_headers_from_httpbin($response);
    dt_dump_headers_from_httpbin($headers, [
        'x-datadog-parent-id',
        'x-datadog-origin',
        'x-mas',
        'x-my-custom-header',
    ]);
    echo PHP_EOL;
}

echo 'Done.' . PHP_EOL;

if (PHP_VERSION_ID < 80000) {
    echo "Successfully triggered flush with trace of size 3", PHP_EOL;
}

?>
--EXPECTF--
Response #0
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
x-mas: tree
x-my-custom-header: foo

Response #1
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
x-mas: tree
x-my-custom-header: foo

Done.
Successfully triggered flush with trace of size 3
