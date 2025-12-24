--TEST--
Distributed tracing headers propagate via file_get_contents() with pre-existing headers as array.
--SKIPIF--
<?php
if (!getenv('HTTPBIN_HOSTNAME')) {
    die('skip: HTTPBIN_HOSTNAME env var required');
}
?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_HTTPSTREAM_ENABLED=1
--FILE--
<?php

$port = getenv('HTTPBIN_PORT') ?: '80';
$host = getenv('HTTPBIN_HOSTNAME');
$url = 'http://' . $host . ':' . $port . '/headers';

DDTrace\trace_function('file_get_contents', function (\DDTrace\SpanData $span) {
    $span->name = 'httpstream';
});

function fetch_with_headers(array $headers)
{
    // Build a non-interned string so that its lifetime is tied to the stream context and this test can
    // catch refcount/ownership bugs under ASAN.
    $method = sprintf('%s%s%s', 'G', 'E', 'T');
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
        ],
    ]);
    // The context now holds its own reference; drop the local variable early so the string lifetime
    // is tied to the stream context.
    unset($method);
    return file_get_contents($GLOBALS['url'], false, $ctx);
}

$responses = [];

$responses[] = fetch_with_headers([
    'x-foo: one',
    'x-bar: alpha',
]);

$responses[] = fetch_with_headers([
    'x-foo: two',
    'x-bar: beta',
    'x-datadog-sampling-priority: 123',
]);

include 'distributed_tracing.inc';
foreach ($responses as $key => $response) {
    echo 'Response #' . $key . PHP_EOL;
    $headers = dt_decode_headers_from_httpbin($response);
    dt_dump_headers_from_httpbin($headers, [
        'x-datadog-trace-id',
        'x-datadog-parent-id',
        'x-datadog-sampling-priority',
        'x-datadog-tags',
        'traceparent',
        'tracestate',
        'x-foo',
        'x-bar',
    ]);
    echo PHP_EOL;
}

echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
[ddtrace] [warning] Error loading deferred integration DDTrace\Integrations\Filesystem\FilesystemIntegration: Class not loaded and not autoloadable
Response #0
traceparent: %s
tracestate: %s
x-bar: alpha
x-datadog-parent-id: %d
x-datadog-sampling-priority: 1
x-datadog-tags: %s
x-datadog-trace-id: %d
x-foo: one

Response #1
traceparent: %s
tracestate: %s
x-bar: beta
x-datadog-parent-id: %d
x-datadog-sampling-priority: 1
x-datadog-tags: %s
x-datadog-trace-id: %d
x-foo: two

Done.
[ddtrace] [info] Flushing trace of size 5 to send-queue for %s
