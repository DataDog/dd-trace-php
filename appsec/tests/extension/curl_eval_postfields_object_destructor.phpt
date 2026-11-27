--TEST--
Downstream curl requests: an object CURLOPT_POSTFIELDS is not kept alive
--DESCRIPTION--
ext/curl converts an object CURLOPT_POSTFIELDS when it is set (PHP 8: with
__toString(); PHP 7: from its properties) and keeps no reference to it. The
analysis must not keep one either: the object's destructor runs as soon as
the app drops it, as it would without the analysis, and an object that
references its handle forms no cycle the garbage collector cannot see (it
would be leaked until the end of the request). The handles are tracked
before rinit() too.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 10, 'ok'));

// dynamic properties: PHP 7.0's ext/curl does not handle declared ones
// (indirect zvals) in an object POSTFIELDS
#[\AllowDynamicProperties]
class Body {
    function __construct() { $this->a = '1'; }
    function __toString() { return 'a=1'; }
    function __destruct() { echo "  destructor\n"; }
}

function run($base, $label) {
    echo "$label:\n";
    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = new Body;
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $body = null;
    echo "  dropped\n";
    echo '  ', curl_exec($ch), "\n";

    // a cycle through the handle: collected
    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = new Body;
    $body->ch = $ch;
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_exec($ch);
    $body = $ch = null;
    // nothing left to collect: the handle does not reference the object
    echo "  collected: ", gc_collect_cycles() > 0 ? 'yes' : 'no', "\n";
}

run($base, 'baseline');
var_dump(rinit());
run($base, 'analysed');
var_dump(rshutdown());
?>
--EXPECTF--
baseline:
  destructor
  dropped
  received %d bytes
  destructor
  collected: no
bool(true)
analysed:
  destructor
  dropped
  received %d bytes
  destructor
  collected: no
bool(true)
