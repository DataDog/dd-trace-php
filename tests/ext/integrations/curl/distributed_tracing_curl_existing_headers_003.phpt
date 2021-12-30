--TEST--
Distributed tracing headers propagate existing headers on error: curl_setopt_array()
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--INI--
ddtrace.request_init_hook={PWD}/distributed_tracing_curl_inject.inc
--DESCRIPTION--
Some libraries do not check the return stats when setting curl opts.
@see https://github.com/stripe/stripe-php/blob/33317c9/lib/HttpClient/CurlClient.php#L441

The original headers should still be applied even when there is an error from
setting the curl opts.
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
HTTP_X_DATADOG_ORIGIN=phpt-test
--FILE--
<?php
include 'curl_helper.inc';

DDTrace\trace_function('curl_exec', function (\DDTrace\SpanData $span) {
    $span->name = 'curl_exec';
});

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';
$ch = curl_init();

$res = curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'x-orig-header: foo',
    ],
    /* This is at the end because it generates an error from the libcurl
     * curl_easy_setopt call. curl_setopt_array() will stop traversing the
     * array once an error has ocurred, but the previous opts in the array will
     * remain set.
     *
     * This should hopefully never be a valid enum value in curl.h.
     * @see https://github.com/curl/curl/blob/cfaa035/include/curl/curl.h#L2150-L2164
     */
    CURLOPT_HTTP_VERSION => -42,
]);
if (false === $res) {
    echo "Successfully triggered error from curl_setopt_array()", PHP_EOL, PHP_EOL;
}

$responses = [];
$responses[] = curl_exec($ch);
show_curl_error_on_fail($ch);
$responses[] = curl_exec($ch);
show_curl_error_on_fail($ch);
curl_close($ch);

include 'distributed_tracing.inc';
foreach ($responses as $key => $response) {
    echo 'Response #' . $key . PHP_EOL;
    $headers = dt_decode_headers_from_httpbin($response);
    dt_dump_headers_from_httpbin($headers, [
        'x-datadog-parent-id',
        'x-datadog-origin',
        'x-orig-header',
    ]);
    echo PHP_EOL;
}

echo 'Done.' . PHP_EOL;

?>
--EXPECTF--
Successfully triggered error from curl_setopt_array()

Response #0
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
x-orig-header: foo

Response #1
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
x-orig-header: foo

Done.
Successfully triggered flush with trace of size 3
