--TEST--
Distributed tracing span is generated for file_get_contents()
--SKIPIF--
<?php
if (!getenv('HTTPBIN_HOSTNAME')) {
    die('skip: HTTPBIN_HOSTNAME env var required');
}
?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTPSTREAM_ENABLED=1
--FILE--
<?php

$port = getenv('HTTPBIN_PORT') ?: '80';
$host = getenv('HTTPBIN_HOSTNAME');
$url = 'http://' . $host . ':' . $port . '/headers';

DDTrace\trace_function('file_get_contents', function (\DDTrace\SpanData $span) {
    $span->name = 'httpstream';
});

$response = file_get_contents($url);

include 'distributed_tracing.inc';
$headers = dt_decode_headers_from_httpbin($response);
dt_dump_headers_from_httpbin($headers, [
    'x-datadog-trace-id',
    'x-datadog-parent-id',
    'x-datadog-sampling-priority',
    'x-datadog-tags',
    'traceparent',
    'tracestate',
]);

echo "\n=== Span ===\n";
$spans = dd_trace_serialize_closed_spans();
$span = $spans[1];

echo "name: " . $span['name'] . "\n";
echo "resource: " . $span['resource'] . "\n";
echo "type: " . $span['type'] . "\n";
echo "service: " . $span['service'] . "\n";
echo "meta:\n";
ksort($span['meta']);
foreach ($span['meta'] as $k => $v) {
    echo "  $k: $v\n";
}

echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
[ddtrace] [warning] Error loading deferred integration DDTrace\Integrations\Filesystem\FilesystemIntegration: Class not loaded and not autoloadable
traceparent: %s
tracestate: %s
x-datadog-parent-id: %d
x-datadog-sampling-priority: 1
x-datadog-tags: %s
x-datadog-trace-id: %d

=== Span ===
name: file_get_contents
resource: %s
type: cli
service: %s
meta:
  component: php.stream
  http.url: http://%s:%d/headers
  network.destination.name: %s
  span.kind: client
Done.
[ddtrace] [info] No finished traces to be sent to the agent
