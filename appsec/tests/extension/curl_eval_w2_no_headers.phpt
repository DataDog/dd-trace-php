--TEST--
Downstream curl requests: W2 carries only the URL and the method
--DESCRIPTION--
A POST with headers, a cookie and a body follows a 302 that
keeps the POST (CURLOPT_POSTREDIR): libcurl sends all of them again to the
redirect target, but the request-stage call of hop 1 (W2, made at its
PREREQ) carries only server.io.net.url and server.io.net.request.method, as
CURLINFO_EFFECTIVE_URL and CURLINFO_EFFECTIVE_METHOD report them: no
request headers and no request body. Hop 0 (W1) has them, from the options.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
// without it, no hop after the first is evaluated (G6.11)
curl_skipif_needs_prereq('the expected calls');
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
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/redirect-to?u=%2Fjson");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_POSTREDIR, CURL_REDIR_POST_302);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Custom: v', 'Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_COOKIE, 'c=1');
curl_setopt($ch, CURLOPT_POSTFIELDS, 'a=b');
var_dump(curl_exec($ch));
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
string(22) "{"status":"ok","n":42}"
bool(true)
curl-1-0 {"server.io.net.request.body":{"a":"b"},"server.io.net.request.headers":{"content-type":["application/x-www-form-urlencoded"],"cookie":["c=1"],"host":["127.0.0.1:PORT"],"x-custom":["v"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/redirect-to?u=%2Fjson"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/json"]},"server.io.net.response.status":"302"}
curl-1-1 {"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-1 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
