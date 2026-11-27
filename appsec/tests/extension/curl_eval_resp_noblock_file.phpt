--TEST--
Downstream curl requests: a blocking response verdict with CURLOPT_FILE is ignored
--DESCRIPTION--
ext/curl writes the response to the file; the verdict of the response body
call is not acted on, so the app gets to use the file.
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
$file = tempnam(sys_get_temp_dir(), 'cdfile');
register_shutdown_function(function () use ($file) {
    echo "== written by ext/curl to the file: ", filesize($file), " bytes\n";
    unlink($file);
});
$helper = ce_helper(['ok', 'ok', 'ok', 'block'], false);
ce_print_at_shutdown($helper, $log);
rinit();
$fp = fopen($file, 'wb');
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_exec($ch);
echo "curl_exec returned\n";
?>
--EXPECT--
curl_exec returned
== written by ext/curl to the file: 22 bytes

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
== server log
GET /json
