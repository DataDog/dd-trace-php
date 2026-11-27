--TEST--
Downstream curl requests: a calibration refused at startup leaves the whole layer off
--DESCRIPTION--
G3.8, G5.1: when the calibration of libcurl's callback slots refuses (here
forced by the testing-only _DD_APPSEC_TESTING_CURL_CALIBRATION_REFUSE, read
where the calibration runs, at zend extension startup), the layer is off: no
offsets are kept, no curl hook is installed (a handle has no tracked state,
which the curl_init hook would have created) and a transfer made inside a
request gets no WAF call. The linkage detection itself is still on.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_testing.php';
if (!extension_loaded('curl')) {
    die('skip ext/curl not loaded');
}
$info = \datadog\appsec\testing\curl_linkage_info();
if (!$info['enabled']) {
    die('skip native layer disabled: ' . $info['reason']);
}
?>
--INI--
datadog.appsec.enabled=1
--ENV--
_DD_APPSEC_TESTING_CURL_CALIBRATION_REFUSE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_linkage_info};
require __DIR__ . '/inc/curl_eval.php';
$info = curl_linkage_info();
var_dump($info['enabled']);
var_dump($info['calibration_accepted']);
var_dump($info['calibration_reason']);
var_dump(array_sum($info['calibration_offsets']));
var_dump($info['layer_enabled']);

$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
cd_dump($ch, 'handle');
var_dump(rshutdown());
$execs = ce_execs($helper);
echo count($execs), " request_exec call(s)\n";
?>
--EXPECTF--
bool(true)
bool(false)
string(%d) "refused for testing (_DD_APPSEC_TESTING_CURL_CALIBRATION_REFUSE)"
int(0)
bool(false)
bool(true)
string(22) "{"status":"ok","n":42}"
== handle
not tracked
bool(true)
0 request_exec call(s)
