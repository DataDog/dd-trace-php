--TEST--
Downstream curl requests: a blocking verdict on the response of an intermediate hop is ignored
--DESCRIPTION--
The response call of hop 0 of a redirect runs inside the PREREQ callback of
the next hop, that is, inside libcurl, and gets a blocking verdict. It is not
acted on (G6.9, G10.14, G10.15): the next hop is requested and evaluated, and
the app gets the final response and no block page.
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
use function datadog\appsec\testing\rinit;
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
// the second call is the response of hop 0
$helper = ce_helper(['ok', 'block', 'ok', 'ok', 'ok', 'ok'], false);
ce_print_at_shutdown($helper, $log);
rinit();
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$res = curl_exec($ch);
echo "response: ", var_export($res, true), "\n";
echo "errno: ", curl_errno($ch), " hops: ", curl_getinfo($ch, CURLINFO_REDIRECT_COUNT), "\n";
?>
--EXPECT--
response: '{"status":"ok","n":42}'
errno: 0 hops: 1

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/json"]},"server.io.net.response.status":"302"}
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-1 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
== server log
GET /redirect
GET /json
