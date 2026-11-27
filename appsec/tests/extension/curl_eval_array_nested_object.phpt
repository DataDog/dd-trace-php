--TEST--
Downstream curl requests: an object in an array-valued field makes ext/curl throw and skips the transfer
--DESCRIPTION--
G7.7: from PHP 8.2 ext/curl throws for an object among the elements of an
array-valued field (checked on 8.3 and 8.5): the option is not applied, and
the transfer, which has no usable body to analyse, is skipped: no WAF call.
(Below 8.2 the nested array is stringified without looking at its elements,
see curl_eval_array_nested_field.)
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 80200) die('skip PHP 8.2+');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
try {
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['a' => [new stdClass]]);
    echo "no exception\n";
} catch (Error $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
curl_exec($ch);
var_dump(rshutdown());
echo count(ce_execs($helper)), " WAF calls for the transfer\n";
?>
--EXPECT--
bool(true)
Error: Object of class stdClass could not be converted to string
bool(true)
0 WAF calls for the transfer
