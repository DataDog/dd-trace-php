--TEST--
Distributed tracing headers propagate with curl_exec()
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--FILE--
<?php
include 'curl_helper.inc';
include 'distributed_tracing.inc';

function query_headers() {
    $port = getenv('HTTPBIN_PORT') ?: '80';
    $url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    show_curl_error_on_fail($ch);
    curl_close($ch);
    return dt_decode_headers_from_httpbin($response);
}

$rootSpan = DDTrace\active_span();
$rootSpan->metrics["_sampling_priority_v1"] = 2;

dt_dump_headers_from_httpbin(query_headers(), ['x-datadog-sampling-priority']);

var_dump(isset($headers['x-datadog-sampling-priority']));

echo 'Done.' . PHP_EOL;

?>
--EXPECTF--
x-datadog-sampling-priority: 2
bool(false)
Done.
