--TEST--
Downstream curl requests: an object among the properties of an object CURLOPT_POSTFIELDS skips the transfer (PHP 7)
--DESCRIPTION--
ext/curl converts an object value of an object body to a string (with
__toString()). The snapshot of the properties recorded by the hook cannot hold
it as it is sent without calling the method again, so the transfer is
skipped as unknown_option, like one with an object element in an array body.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID >= 80000) die('skip PHP 7 only');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));

class Part {
    public static $n = 0;
    function __toString() { self::$n++; return 'part'; }
}

var_dump(rinit());
$o = (object)['field' => new Part];
$ch = curl_init("$base/len");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $o);
echo curl_exec($ch) !== false ? "sent\n" : "failed\n";
echo "__toString() calls: ", Part::$n, "\n";
$d = curl_downstream_dump($ch);
echo "observed=", var_export($d['observed'], true),
    " reason=", var_export($d['reason'], true), "\n";
var_dump(rshutdown());
?>
--EXPECT--
bool(true)
sent
__toString() calls: 1
observed=false reason='unknown_option'
bool(true)
