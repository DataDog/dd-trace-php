--TEST--
Downstream curl requests are evaluated natively: every subcontext is closed
--DESCRIPTION--
A transfer that fails (connection refused) and transfers removed from
their multi handle mid-transfer (curl_multi_remove_handle,
curl_multi_close) still get a last call in each subcontext they opened.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
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
// more responses than calls; the remaining ones are never used
$helper = ce_helper(array_fill(0, 20, 'ok'), false);
var_dump(rinit());
$ch = curl_init("http://127.0.0.1:1/refused");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));

$mh = curl_multi_init();
$ch = curl_init("$base/big?n=5000000");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
curl_multi_exec($mh, $running);
curl_multi_remove_handle($mh, $ch);

$ch = curl_init("$base/big?n=5000000");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
curl_multi_exec($mh, $running);
curl_multi_close($mh);

$execs = ce_execs($helper);
ce_print(array_slice($execs, 0, 2));
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
bool(false)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/refused"}
curl-1-0 last []
3 subcontext(s), all closed
