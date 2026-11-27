--TEST--
Downstream curl requests: a Stringable CURLOPT_URL whose __toString() throws skips the transfer
--DESCRIPTION--
When __toString() throws, the exception propagates, the hook leaves the URL as
ext/curl does (it applies the empty string; before PHP 7.4 the engine's fatal
error cannot be caught) and the transfer is skipped as unknown_option. The
method runs once, in the hook.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 70400) die('skip PHP 7.4+: before, the exception is a fatal error');
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

class Throwing {
    public static $n = 0;
    function __toString() { self::$n++; throw new Exception('boom'); }
}

var_dump(rinit());
$ch = curl_init("$base/json?before_throw=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
try {
    curl_setopt($ch, CURLOPT_URL, new Throwing);
    echo "no exception\n";
} catch (Exception $e) {
    echo "threw: ", $e->getMessage(), "\n";
}
echo "__toString() calls: ", Throwing::$n, "\n";
var_dump(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
var_dump(curl_exec($ch));
$d = curl_downstream_dump($ch);
echo "observed=", var_export($d['observed'], true),
    " reason=", var_export($d['reason'], true), "\n";
var_dump(rshutdown());
var_dump(count(ce_execs($helper)));
?>
--EXPECT--
bool(true)
threw: boom
__toString() calls: 1
string(0) ""
bool(false)
observed=false reason='unknown_option'
bool(true)
int(0)
