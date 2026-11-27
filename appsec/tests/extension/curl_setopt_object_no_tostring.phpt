--TEST--
Downstream curl requests: a curl_setopt() that throws leaves the handle unknown_option
--DESCRIPTION--
An object without __toString() as the value of a string option makes ext/curl
throw (an Error from PHP 7.4; before, a fatal error). What it applied is not
known, so nothing is recorded for the call and the transfer is skipped
(G4.11).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 70400) die('skip PHP 7.4+: before, the conversion is a fatal error');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));

var_dump(rinit());
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
try {
    curl_setopt($ch, CURLOPT_COOKIE, new stdClass);
} catch (Error $e) {
    echo "threw ", get_class($e), "\n";
}
curl_exec($ch);
$d = curl_downstream_dump($ch);
echo "observed=", var_export($d['observed'], true),
    " reason=", var_export($d['reason'], true), "\n";
var_dump(rshutdown());
?>
--EXPECT--
bool(true)
threw Error
observed=false reason='unknown_option'
bool(true)
