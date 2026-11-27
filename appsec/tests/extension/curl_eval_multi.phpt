--TEST--
Downstream curl requests are evaluated natively: curl_multi
--DESCRIPTION--
Two handles in a multi handle: each transfer is evaluated in its own
subcontext, its first call made by the first curl_multi_exec().
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());
$mh = curl_multi_init();
$ch1 = curl_init("$base/json");
$ch2 = curl_init("$base/xml");
foreach ([$ch1, $ch2] as $ch) {
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_multi_add_handle($mh, $ch);
}
do {
    $status = curl_multi_exec($mh, $running);
    while (curl_multi_info_read($mh) !== false) {
    }
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
var_dump(curl_multi_getcontent($ch1), curl_multi_getcontent($ch2));
curl_multi_remove_handle($mh, $ch1);
curl_multi_remove_handle($mh, $ch2);

var_dump(rshutdown());
$execs = ce_execs($helper);
// the transfers run concurrently: group the calls by subcontext
$groups = [];
foreach ($execs as $e) {
    $groups[$e[0]][] = $e;
}
ksort($groups);
ce_print(array_merge(...array_values($groups)));
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
string(22) "{"status":"ok","n":42}"
string(37) "<doc><item attr="v">text</item></doc>"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/xml"}
curl-2-0 last {"server.io.net.response.body":{"doc":[{"item":[{"@attr":"v"},"text"]}]},"server.io.net.response.headers":{"connection":["close"],"content-length":["37"],"content-type":["application/xml"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
