--TEST--
Test CurlMulti during garbage collection
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
include 'curl_helper.inc';
include 'distributed_tracing.inc';

DDTrace\trace_function('doMulti', function (\DDTrace\SpanData $span) {
    $span->name = 'doMulti';
});

function dumpHeaders($ch)
{
    $response = curl_multi_getcontent($ch);
    $headers = dt_decode_headers_from_httpbin($response);
    dt_dump_headers_from_httpbin($headers, [
        'x-datadog-parent-id',
        'x-datadog-origin',
    ]);
}

function doMulti($url)
{
    $mh_copy = $mh = curl_multi_init();
    $ch = [];

    for ($i = 0; $i < 46; ++$i) {
        $ch[$i] = curl_init();
        curl_setopt($ch[$i], CURLOPT_URL, $url);
        curl_setopt($ch[$i], CURLOPT_RETURNTRANSFER, true);

        curl_multi_add_handle($mh, $ch[$i]);
    }


    unset($mh_copy); // Add to GC root buffer
    gc_collect_cycles();

    do {
        $status = curl_multi_exec($mh, $active);
        curl_multi_select($mh);
    } while ($active > 0 && $status === CURLM_OK);

    show_curl_multi_error_on_fail($status);
    for ($i = 0; $i < 46; ++$i) {
        show_curl_error_on_fail($ch[$i]);
    }

    dumpHeaders($ch[0]);
    dumpHeaders($ch[45]);

    unset($mh);
    gc_collect_cycles();
}

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';

doMulti($url);

echo 'Done.' . PHP_EOL;

?>
--EXPECTF--
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
Done.
Flushing trace of size 2 to send-queue for %s
