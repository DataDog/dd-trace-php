--TEST--
Downstream curl requests: a slot given back while another transfer is in flight
--DESCRIPTION--
With a limit of 2 and a rate of 1, a multi handle runs a GET that sends no body
next to a slow POST with a JSON body. When the GET has ended (the POST is still
in flight) it has given its slot back, so a third handle, a JSON POST added
then, takes it and has its request body analysed. Without the release the limit
would be used up by the GET and the POST in flight (G8.11). The metric counts
the transfers whose body was added: 2.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=2
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 12, 'ok'));
var_dump(rinit());
function post($url, $body)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    return $ch;
}
$mh = curl_multi_init();
$get = curl_init("$base/gzip");
curl_setopt($get, CURLOPT_RETURNTRANSFER, true);
$slow = post("$base/slow", '{"slow":1}');
curl_multi_add_handle($mh, $get);
curl_multi_add_handle($mh, $slow);
$get_done = false;
$t = microtime(true);
do {
    curl_multi_exec($mh, $running);
    while ($info = curl_multi_info_read($mh)) {
        $get_done = $get_done || $info['handle'] === $get;
    }
    if ($running && !$get_done) {
        curl_multi_select($mh, 0.01);
    }
} while ($running == 2 && !$get_done && microtime(true) - $t < 5);
echo 'get ended, slow POST in flight: ', cd_yn($get_done && $running), "\n";
$third = post("$base/echo", '{"third":3}');
curl_multi_add_handle($mh, $third);
do {
    $status = curl_multi_exec($mh, $running);
    while (curl_multi_info_read($mh) !== false) {
    }
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
foreach ([$get, $slow, $third] as $ch) {
    curl_multi_remove_handle($mh, $ch);
}
var_dump(rshutdown());
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);
$execs = ce_execs($helper);
$with_body = [];
foreach ($execs as list($id, $last, $data)) {
    if (isset($data['server.io.net.request.body'])) {
        $with_body[] = json_encode($data['server.io.net.request.body']);
    }
}
sort($with_body);
echo 'request bodies: ', implode(' ', $with_body), "\n";
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
get ended, slow POST in flight: yes
bool(true)
float(2)
request bodies: {"slow":1} {"third":3}
3 subcontext(s), all closed
