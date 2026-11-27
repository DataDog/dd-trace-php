--TEST--
Downstream curl requests: no metric when only non-body addresses were evaluated
--DESCRIPTION--
A transfer evaluated for its URL, method and headers only (its response is
content-encoded, so no body is analysed) leaves
_dd.appsec.downstream_request unset on the service-entry span (G8.13).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
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
$helper = ce_helper(array_fill(0, 2, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/gzip");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(strlen(curl_exec($ch)));
var_dump(rshutdown());
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);
?>
--EXPECT--
bool(true)
int(37)
bool(true)
NULL
