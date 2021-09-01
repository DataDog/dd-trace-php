--TEST--
Distributed tracing headers propagate after curl_copy_handle()
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--INI--
ddtrace.request_init_hook={PWD}/distributed_tracing_curl_inject.inc
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
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
    CURLOPT_HTTPHEADER => [
        'x-foo: before-the-copy',
        'x-bar: theory',
    ],
]);

$responses = [];
$responses[] = curl_exec($ch);
$responses[] = curl_exec($ch);

$ch2 = curl_copy_handle($ch);

$responses[] = curl_exec($ch2);

curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'x-foo: after-the-copy',
    'x-bar: linguistics',
]);
$responses[] = curl_exec($ch2);

curl_close($ch);
curl_close($ch2);

include 'distributed_tracing.inc';
foreach ($responses as $key => $response) {
    echo 'Response #' . $key . PHP_EOL;
    $headers = dt_decode_headers_from_httpbin($response);
    dt_dump_headers_from_httpbin($headers, [
        'x-datadog-trace-id',
        'x-datadog-parent-id',
        'x-foo',
        'x-bar',
    ]);
    echo PHP_EOL;
}

echo 'Done.' . PHP_EOL;

if (PHP_VERSION_ID < 70000) {
    echo "Successfully triggered flush with trace of size 5", PHP_EOL;
}

?>
--EXPECTF--
Response #0
x-bar: theory
x-datadog-parent-id: %d
x-foo: before-the-copy

Response #1
x-bar: theory
x-datadog-parent-id: %d
x-foo: before-the-copy

Response #2
x-bar: theory
x-datadog-parent-id: %d
x-foo: before-the-copy

Response #3
x-bar: linguistics
x-datadog-parent-id: %d
x-foo: after-the-copy

Done.
Successfully triggered flush with trace of size 5
