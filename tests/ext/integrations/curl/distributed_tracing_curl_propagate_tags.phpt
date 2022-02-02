--TEST--
Distributed tracing header tags propagate with curl_exec()
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
HTTP_X_DATADOG_TAGS=custom_tag=inherited,to_remove=,foo=bar,_dd.p.upstream_services=abcdef|0|2|1.000
--FILE--
<?php

include 'distributed_tracing.inc';

function query_headers() {
    $port = getenv('HTTPBIN_PORT') ?: '80';
    $url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return dt_decode_headers_from_httpbin($response);
}

$meta = &DDTrace\root_span()->meta;
unset($meta["to_remove"]);
$meta["foo"] = "buzz";

$span = DDTrace\start_span();
$span->service = "dd";

DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_USER_REJECT);

dt_dump_headers_from_httpbin(query_headers(), ['x-datadog-tags']);

?>
--EXPECT--
x-datadog-tags: custom_tag=inherited,foo=buzz,_dd.p.upstream_services=abcdef|0|2|1.000;ZGQ|-1|4|
