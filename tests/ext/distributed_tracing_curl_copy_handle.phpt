--TEST--
Distributed tracing headers propagate after curl_copy_handle()
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
dd_trace_distributed_tracing_headers([
    'x-datadog-trace-id: 1234',
    'x-datadog-parent-id: 1337', // Should be replaced by active span ID
]);

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
    dt_dump_headers_from_httpbin($response, [
        'x-datadog-trace-id',
        'x-datadog-parent-id',
        'x-foo',
        'x-bar',
    ]);
    echo PHP_EOL;
}

echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Done.
