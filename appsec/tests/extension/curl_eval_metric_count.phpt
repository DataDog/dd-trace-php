--TEST--
Downstream curl requests: the metric is the number of transfers with a body analysed
--DESCRIPTION--
With a limit of 3, two transfers whose bodies are analysed make the metric
_dd.appsec.downstream_request 2.0, even though a third transfer evaluated only
its URL and headers (its response is content-encoded: its slot was given
back). The metric is absent when only non-body addresses were evaluated: see
curl_eval_metric_absent.phpt (G8.13).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('counting the response body');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=3
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$ok = response_list(response_request_exec([[['ok', []]]]));
$shutdown = response_list(response_request_shutdown(
    [[['ok', []]], new ArrayObject(), new ArrayObject()]));
$init = response_list(response_request_init([[['ok', []]]]));
$helper = Helper::createInitedRun(array_merge(
    [$init], array_fill(0, 6, $ok), [$shutdown]));

function get($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    return strlen(curl_exec($ch));
}

var_dump(rinit());
var_dump(get("$base/json"));
$ch = curl_init("$base/echo-json");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => '{"a":1}',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);
var_dump(curl_exec($ch));
var_dump(get("$base/gzip"));
var_dump(rshutdown());
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);
?>
--EXPECT--
bool(true)
int(22)
string(7) "{"a":1}"
int(37)
bool(true)
float(2)
