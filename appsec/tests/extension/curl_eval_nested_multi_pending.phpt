--TEST--
Downstream curl requests: a nested curl_multi_exec drops the pending entries of its multi handle
--DESCRIPTION--
A handle added to a multi handle starts to be evaluated (W1) at the first
curl_multi_exec() of that multi handle. When that exec is nested, that is,
made from a callback that libcurl runs for another transfer, the hook does
nothing (G4.3): the transfer is not observed, and the entries pending for
that multi handle are dropped, so that a later exec outside libcurl does
not evaluate them, with the options of that moment (G6.12).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 10, 'ok'));
rinit();

$mh = curl_multi_init();
$n1 = curl_init("$base/json?n=1");
curl_setopt($n1, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $n1); // pending: starts at the first exec

$a = curl_init("$base/big?n=200000");
$writes = 0;
curl_setopt($a, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($mh, &$writes) {
    if ($writes++ === 0) {
        curl_multi_exec($mh, $running); // nested: not observed
    }
    return strlen($data);
});
var_dump(curl_exec($a));

// outside libcurl: no W1 for $n1
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
var_dump(curl_multi_getcontent($n1));
curl_multi_remove_handle($mh, $n1);

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
string(22) "{"status":"ok","n":42}"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/big?n=200000"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["200000"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
