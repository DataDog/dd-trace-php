--TEST--
Downstream curl requests are evaluated natively: a decoded text/plain response has no body
--DESCRIPTION--
A gzip response decoded by libcurl (CURLOPT_ENCODING): the head is the last
call. The body bytes are decoded (G7.17), but text/plain is not a parsed
content type (G7.8), so no body is sent although the transfer is sampled.
A decoded JSON body is analysed: curl_eval_gzip_decoded.phpt.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 3, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/gzip");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
var_dump(curl_exec($ch));
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
string(17) "hello gzip world
"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/gzip"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-encoding":["gzip"],"content-length":["37"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
