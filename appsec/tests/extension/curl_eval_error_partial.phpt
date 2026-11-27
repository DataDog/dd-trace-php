--TEST--
Downstream curl requests: a curl_multi transfer ended by an error flushes its partial hops in order
--DESCRIPTION--
G4.18, G6.6, G9.7: the redirect is followed to a server that sends the head
and part of the body, then stalls until CURLOPT_TIMEOUT_MS ends the transfer
(CURLE_OPERATION_TIMEDOUT). The app only polls curl_multi_exec(): the exec
that finds nothing running flushes the transfer. The intermediate hop got its
response call from the PREREQ of the next one; the last hop gets its response
call with the status, the headers and the body bytes received before the
error, with subctx_last_call. Every call comes once and in order.
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
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$mh = curl_multi_init();
$ch = curl_init("$base/redirect-to?u=/stall%3Fjson%3D1");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT_MS => 1000]);
curl_multi_add_handle($mh, $ch);
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 0.2);
    }
} while ($running && $status == CURLM_OK);
echo "exec status: $status, running: $running\n";
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
exec status: 0, running: 0
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect-to?u=/stall%3Fjson%3D1"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/stall?json=1"]},"server.io.net.response.status":"302"}
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/stall?json=1"}
curl-1-1 last {"server.io.net.response.body":{"a":"0123456789"},"server.io.net.response.headers":{"connection":["close"],"content-length":["30"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
