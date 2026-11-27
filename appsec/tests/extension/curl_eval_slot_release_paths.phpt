--TEST--
Downstream curl requests: every way a sampled transfer ends without a body gives its slot back
--DESCRIPTION--
With a limit of 1 and a rate of 1 each transfer takes the only slot at its
start. Three transfers end without a body being added: one whose handle is
removed from its multi handle and freed while it is in flight (G4.5), a POST
whose JSON body does not parse and a POST with an empty body (G7.18). Each
must give its slot back: the POST with a valid body that follows has its
request body analysed, and the one after that finds the slot taken. (A blocked
transfer aborts the request, so no later transfer can show its slot.)
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
$lv = \datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'];
// see curl_eval_multi_freed.phpt: libcurl 8.1.0 - 8.9.x
if ($lv >= 0x080100 && $lv < 0x080a00) die('skip libcurl 8.1.0-8.9.x crashes there');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 20, 'ok'));
var_dump(rinit());

// (a) a sampled transfer freed in flight
$mh = curl_multi_init();
$ch = curl_init("$base/slow");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
curl_multi_exec($mh, $running);
curl_multi_select($mh, 0.05);
curl_multi_exec($mh, $running);
echo 'running: ', cd_yn($running), "\n";
curl_multi_remove_handle($mh, $ch);
unset($ch);
unset($mh);

// (c) an unparseable and an empty body, then a valid one, then another
foreach (['not json', '', '{"b":2}', '{"c":3}'] as $body) {
    $ch = curl_init("$base/echo");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    curl_exec($ch);
    unset($ch);
}
var_dump(rshutdown());
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);
$execs = ce_execs($helper);
foreach ($execs as list($id, $last, $data)) {
    echo $id, ': ', isset($data['server.io.net.request.body']) ?
        json_encode($data['server.io.net.request.body']) : '-', "\n";
}
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
running: yes
bool(true)
float(1)
curl-1-0: -
curl-1-0: -
curl-2-0: -
curl-2-0: -
curl-3-0: -
curl-3-0: -
curl-4-0: {"b":2}
curl-4-0: -
curl-5-0: -
curl-5-0: -
5 subcontext(s), all closed
