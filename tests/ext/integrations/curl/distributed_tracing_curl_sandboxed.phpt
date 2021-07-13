--TEST--
Calls to curl_inject_distributed_headers() are sandboxed
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
<?php if (PHP_VERSION_ID >= 80000) die('skip: Test obsolete with internal distributed tracing handling'); ?>
--INI--
ddtrace.request_init_hook={PWD}/distributed_tracing_curl_inject_exception.inc
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
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'x-my-custom-header: foo',
]);
$response = curl_exec($ch);
curl_close($ch);

include 'distributed_tracing.inc';
$headers = dt_decode_headers_from_httpbin($response);
dt_dump_headers_from_httpbin($headers, [
    'x-datadog-parent-id',
    'x-datadog-origin',
    'x-my-custom-header',
]);

echo 'Done.' . PHP_EOL;

if (PHP_VERSION_ID < 80000) {
    echo "Successfully triggered flush with trace of size 2", PHP_EOL;
}

?>
--EXPECT--
x-my-custom-header: foo
Done.
Successfully triggered flush with trace of size 2
