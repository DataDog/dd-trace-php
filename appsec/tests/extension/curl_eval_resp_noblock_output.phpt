--TEST--
Downstream curl requests: a blocking response verdict with the response going to the output is ignored
--DESCRIPTION--
Without RETURNTRANSFER ext/curl writes the response to PHP's output; the
verdict of the response body call is not acted on, so the output buffer
holds the whole response.
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
ob_start();
echo "app output before\n";
$ch = curl_init("$base/json");
curl_exec($ch);
echo "after curl_exec\n";
$buffer = ob_get_clean();
echo "buffer: ", json_encode($buffer), "\n";
?>
--EXPECT--
buffer: "app output before\n{\"status\":\"ok\",\"n\":42}after curl_exec\n"

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
== server log
GET /json
