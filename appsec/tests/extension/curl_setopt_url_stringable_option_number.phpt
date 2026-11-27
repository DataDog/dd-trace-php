--TEST--
Downstream curl requests: a Stringable CURLOPT_URL is converted once whatever the type of the option number
--DESCRIPTION--
In weak mode ext/curl takes the option number from a numeric string, a float
or a bool; the hook does the same before deciding whether the value is
converted (G4.7), so __toString() runs once, in the hook (G4.8), and the URL
is recorded. A throwing __destruct() of the object, run when the conversion
releases it, propagates and skips the transfer (G4.11).
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
$helper = ce_helper(array_fill(0, 30, 'ok'));

class U {
    public static $n = 0;
    private $s;
    function __construct($s) { $this->s = $s; }
    function __toString() { self::$n++; return $this->s; }
}
class DtorThrows extends U {
    function __destruct() { throw new Exception('dtor'); }
}

var_dump(rinit());
foreach (['string' => (string)CURLOPT_URL, 'float' => (float)CURLOPT_URL] as $label => $opt) {
    U::$n = 0;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    var_dump(curl_setopt($ch, $opt, new U("$base/json?$label")));
    echo "$label: __toString() calls: ", U::$n, "\n";
    $d = curl_downstream_dump($ch);
    echo "$label: recorded ", var_export(str_replace($base, 'BASE', $d['options']['url']), true), "\n";
    echo "$label: ", curl_exec($ch), "\n";
}

U::$n = 0;
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
try {
    curl_setopt($ch, CURLOPT_URL, new DtorThrows("$base/json"));
    echo "no exception\n";
} catch (Exception $e) {
    echo "threw ", $e->getMessage(), "\n";
}
echo "__toString() calls: ", U::$n, "\n";
curl_exec($ch);
$d = curl_downstream_dump($ch);
echo "observed=", var_export($d['observed'], true), " reason=", var_export($d['reason'], true), "\n";
var_dump(rshutdown());
?>
--EXPECT--
bool(true)
bool(true)
string: __toString() calls: 1
string: recorded 'BASE/json?string'
string: {"status":"ok","n":42}
bool(true)
float: __toString() calls: 1
float: recorded 'BASE/json?float'
float: {"status":"ok","n":42}
threw dtor
__toString() calls: 1
observed=false reason='unknown_option'
bool(true)
