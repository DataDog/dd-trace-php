--TEST--
Downstream curl requests: overlapping multi transfers do not exceed the body limit
--DESCRIPTION--
With a limit of 1 and a rate of 1, two POSTs with JSON bodies run at once in
one multi handle. The slot is reserved at the decision, when each transfer
starts, so the second finds it taken even though the first has not sent its
body to the WAF yet: only one of them has its request body analysed and the
metric is 1 (G8.11, G8.13).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
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
$helper = ce_helper(array_fill(0, 10, 'ok'));
var_dump(rinit());
$mh = curl_multi_init();
$chs = [];
foreach (['{"a":1}', '{"b":2}'] as $body) {
    $ch = curl_init("$base/echo");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    curl_multi_add_handle($mh, $ch);
    $chs[] = $ch;
}
do {
    $status = curl_multi_exec($mh, $running);
    while (curl_multi_info_read($mh) !== false) {
    }
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
foreach ($chs as $ch) {
    curl_multi_remove_handle($mh, $ch);
}
var_dump(rshutdown());
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);
$execs = ce_execs($helper);
$with_body = 0;
foreach ($execs as list($id, $last, $data)) {
    if (isset($data['server.io.net.request.body'])) {
        $with_body++;
    }
}
echo "calls with a request body: $with_body\n";
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
bool(true)
float(1)
calls with a request body: 1
2 subcontext(s), all closed
