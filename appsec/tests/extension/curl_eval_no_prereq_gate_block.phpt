--TEST--
Downstream curl requests: below libcurl 7.80 the read-function gate can block
--DESCRIPTION--
G6.11, G10.16: without CURLOPT_PREREQFUNCTION the read-function gate of hop 0
still runs, and its blocking verdict aborts the transfer: the interposed read
function returns CURL_READFUNC_ABORT before any body byte is sent and the
request is blocked once libcurl has returned. No call follows the block. The
libcurl versions concerned send the request head before the first read, so
the server got the head and no body byte. curl_eval_no_prereq.phpt asserts
that W1 can block there; curl_eval_gate_block.phpt is the same gate block on
every libcurl.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (\datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] >= 0x075000) {
    die('skip libcurl 7.80.0+ has CURLOPT_PREREQFUNCTION: curl_eval_gate_block.phpt covers it (this test is for libcurl below 7.80)');
}
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
$infile = tempnam(sys_get_temp_dir(), 'cdin');
$helper = null;
// registered before the server is started, so it runs before the server is
// stopped
register_shutdown_function(function () use (&$helper, $log, $infile) {
    global $cd_server_base;
    // the server serves one connection at a time, in order: once this one
    // is served, the aborted one was logged
    file_get_contents("$cd_server_base/json?sync");
    echo "\n== WAF calls\n";
    ce_print(ce_execs($helper));
    echo "== server log\n", file_get_contents($log);
    @unlink($log);
    @unlink($infile);
});
$base = cd_server_start($log);
$helper = ce_helper(['ok', 'block'], false);
rinit();

file_put_contents($infile, '{"a":"attack"}');
$fp = fopen($infile, 'rb');
$ch = curl_init("$base/recv");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILE, $fp);
curl_setopt($ch, CURLOPT_INFILESIZE, 14);
curl_exec($ch);
echo "not reached\n";
?>
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors":[{"title":"You've been blocked","detail":"Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}],"security_response_id":""}
Warning: curl_exec(): Datadog blocked the request and presented a static error page. No action required. Security Response ID:  in %s on line %d

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/recv"}
curl-1-0 {"server.io.net.request.body":{"a":"attack"}}
== server log
PUT /recv
body 0 bytes
GET /json?sync
