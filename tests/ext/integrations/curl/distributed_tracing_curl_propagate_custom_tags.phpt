--TEST--
Distributed tracing header tags propagate with curl_exec()
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
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

DDTrace\add_distributed_tag("usr.id", "1234");
$meta = DDTrace\root_span()->meta;
var_dump($meta["_dd.p.usr.id"]);

dt_dump_headers_from_httpbin(query_headers(), ['x-datadog-tags']);

?>
--EXPECT--
string(4) "1234"
x-datadog-tags: _dd.p.usr.id=1234,_dd.p.dm=-1
