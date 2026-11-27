--TEST--
Downstream curl requests: a Stringable CURLOPT_POSTFIELDS whose __toString() throws skips the transfer (PHP 8)
--DESCRIPTION--
When __toString() throws, the exception propagates, ext/curl is given the
empty string the failed conversion left (it is applied as the empty body) and
the transfer is skipped as unknown_option (G4.8, G4.9, G4.11). The method
runs once, in the hook. A destructor that throws when the conversion released
the last reference to the object is handled the same way: the original
handler does not run with an exception pending.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 80000) die('skip PHP 8+');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));

class Throwing {
    public static $n = 0;
    function __toString(): string { self::$n++; throw new Exception('boom'); }
}
class DtorThrows {
    public static $n = 0;
    function __toString(): string { self::$n++; return '{"a":1}'; }
    function __destruct() { throw new Exception('dtor'); }
}

var_dump(rinit());
foreach (['toString' => function () { return new Throwing; },
          'destructor' => function () { return new DtorThrows; }] as $label => $make) {
    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    try {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $make());
        echo "$label: no exception\n";
    } catch (Exception $e) {
        echo "$label: threw ", $e->getMessage(), "\n";
    }
    echo "$label: __toString() calls: ", Throwing::$n + DtorThrows::$n, "\n";
    Throwing::$n = DtorThrows::$n = 0;
    $r = curl_exec($ch);
    $d = curl_downstream_dump($ch);
    echo "$label: exec ", var_export($r, true), " observed=", var_export($d['observed'], true),
        " reason=", var_export($d['reason'], true), "\n";
}
var_dump(rshutdown());
var_dump(count(ce_execs($helper)));
?>
--EXPECT--
bool(true)
toString: threw boom
toString: __toString() calls: 1
toString: exec 'received 0 bytes' observed=false reason='unknown_option'
destructor: threw dtor
destructor: __toString() calls: 1
destructor: exec 'received 7 bytes' observed=false reason='unknown_option'
bool(true)
int(0)
