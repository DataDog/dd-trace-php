--TEST--
Distributed tracing headers do not propagate with curl_multi_exec() after dd_trace_disable_in_request()
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--INI--
ddtrace.request_init_hook={PWD}/distributed_tracing_curl_inject.inc
--ENV--
DD_TRACE_DEBUG=1
HTTP_X_DATADOG_ORIGIN=phpt-test
--FILE--
<?php
include 'distributed_tracing.inc';

DDTrace\trace_function('doMulti', function (\DDTrace\SpanData $span) {
    $span->name = 'doMulti';
});

function dumpHeaders($ch)
{
    $response = curl_multi_getcontent($ch);
    $headers = dt_decode_headers_from_httpbin($response);
    dt_dump_headers_from_httpbin($headers, [
        'x-foo',
        'x-datadog-parent-id',
        'x-datadog-origin',
    ]);
}

function doMulti($url)
{
    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, $url);
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_HTTPHEADER, [
        'x-foo: foo',
    ]);

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, $url);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'x-foo: foo',
    ]);

    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);

    do {
        curl_multi_exec($mh, $active);
        curl_multi_select($mh);
    } while ($active > 0);

    dumpHeaders($ch1);
    dumpHeaders($ch2);

    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);

    curl_multi_close($mh);
}

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';

doMulti($url);

dd_trace_disable_in_request();

echo 'Disabled.' . PHP_EOL;

doMulti($url);

echo 'Done.' . PHP_EOL;

if (PHP_VERSION_ID < 80000) {
    echo "Successfully triggered flush with trace of size 2", PHP_EOL;
}

?>
--EXPECTF--
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
x-foo: foo
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
x-foo: foo
Disabled.
x-foo: foo
x-foo: foo
Done.
Successfully triggered flush with trace of size 2
