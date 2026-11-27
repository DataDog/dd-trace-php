--TEST--
Downstream curl requests: a blocking response verdict when curl_multi_info_read reports a transfer done is ignored
--DESCRIPTION--
The response body ends when the connection is closed (no Content-Length),
so it is only evaluated once the transfer is reported done by
curl_multi_info_read; its blocking verdict is not acted on.
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
$mh = curl_multi_init();
$ch = curl_init("$base/close?json=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
do {
    curl_multi_exec($mh, $running);
    while (($info = curl_multi_info_read($mh)) !== false) {
        echo "info_read returned\n";
    }
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running);
echo "content: ", curl_multi_getcontent($ch), "\n";
?>
--EXPECT--
info_read returned
content: {"a":"body until close"}

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/close?json=1"}
curl-1-0 last {"server.io.net.response.body":{"a":"body until close"},"server.io.net.response.headers":{"connection":["close"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
== server log
GET /close?json=1
