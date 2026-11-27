--TEST--
Downstream curl requests: a blocking verdict on the response body is ignored
--DESCRIPTION--
The last-hop body call gets a blocking verdict; it is not acted on: the
app gets the whole response and no block page (G6.9, G10.14).
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
use function datadog\appsec\testing\rinit;
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(['ok', 'ok', 'ok', 'block'], false);
ce_print_at_shutdown($helper, $log);
rinit();
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
echo "response: $res\n";
?>
--EXPECT--
response: {"status":"ok","n":42}

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
== server log
GET /json
