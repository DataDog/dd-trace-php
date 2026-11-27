--TEST--
Downstream curl responses: chunk sizes libcurl accepts, and sampling only for bodies with data
--DESCRIPTION--
libcurl accepts chunk sizes of up to 16 hex digits, leading zeros included
(17 since 8.23.0), whose value fits in a curl_off_t; so does the de-chunker,
and such a body is analysed. Whether a transfer's bodies are sent is decided
once it has a body with data: a response with an empty or undecodable body
does not use up the per-request budget
(DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS), and the next one
still gets its body analysed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=2
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'));
var_dump(rinit());

foreach (['chunk16?json=1', 'chunk0?json=1', 'badchunk?json=1', 'json'] as $path) {
    $ch = curl_init("$base/$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    echo "$path: ", var_export(curl_exec($ch), true), "\n";
}

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
chunk16?json=1: '{"a":"0123456789abcde"}'
chunk0?json=1: ''
badchunk?json=1: false
json: '{"status":"ok","n":42}'
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/chunk16?json=1"}
curl-1-0 last {"server.io.net.response.body":{"a":"0123456789abcde"},"server.io.net.response.headers":{"connection":["close"],"content-type":["application/json"],"transfer-encoding":["chunked"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/chunk0?json=1"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-type":["application/json"],"transfer-encoding":["chunked"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/badchunk?json=1"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-type":["application/json"],"transfer-encoding":["chunked"]},"server.io.net.response.status":"200"}
curl-4-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-4-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
4 subcontext(s), all closed
