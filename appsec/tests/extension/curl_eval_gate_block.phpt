--TEST--
Downstream curl requests: a blocking verdict at the read-function gate aborts the transfer before any body byte is sent
--DESCRIPTION--
G6.7, G7.16: the request-stage call of the read-function gate (an INFILE
upload) gets a blocking verdict: the interposed read function returns
CURL_READFUNC_ABORT, so no byte of the body is sent; the transfer ends with
an abort error and the request is blocked once libcurl has returned (the
safe point). No call follows the block (no response call nor closing call
for hop 0). Up to libcurl 8.6 the request head and Content-Length go out
before the first read, so the server got the head and no body byte; from
8.7 nothing is sent before the first read returns, so the server got no
byte at all (it logs "(no request bytes)").
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
use function datadog\appsec\testing\rinit;
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$infile = tempnam(sys_get_temp_dir(), 'cdin');
$new = datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] >= 0x080700;
$helper = null;
// registered before the server is started, so it runs before the server is
// stopped
register_shutdown_function(function () use (&$helper, $log, $infile, $new) {
    global $cd_server_base;
    // the server serves one connection at a time, in order: once this one
    // is served, the aborted one was logged
    file_get_contents("$cd_server_base/json?sync");
    echo "\n== WAF calls\n";
    ce_print(ce_execs($helper));
    echo "== server log\n";
    $got = (string)@file_get_contents($log);
    $expected = ($new ? "(no request bytes)\n" : "PUT /recv\nbody 0 bytes\n") .
        "GET /json?sync\n";
    echo $got === $expected ? "as expected for this libcurl\n" : $got;
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
as expected for this libcurl
