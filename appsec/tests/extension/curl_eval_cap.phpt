--TEST--
Downstream curl requests are evaluated natively: bodies are capped
--DESCRIPTION--
With DD_APPSEC_MAX_BODY_BUFF_SIZE=64, the request body (from
CURLOPT_POSTFIELDS) and the response body (JSON) are converted from their first
64 bytes only;
the response call, made once the transfer is over, carries the first 64 bytes.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_APPSEC_MAX_BODY_BUFF_SIZE=64
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/big?n=100000&json=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{"a":"' . str_repeat('abcdefghij', 10) . '"}');
var_dump(strlen(curl_exec($ch)));
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
int(100000)
bool(true)
curl-1-0 {"server.io.net.request.body":{"a":"abcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefgh"},"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/big?n=100000&json=1"}
curl-1-0 last {"server.io.net.response.body":{"a":"0123456789012345678901234567890123456789012345678901234567"},"server.io.net.response.headers":{"connection":["close"],"content-length":["100000"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
