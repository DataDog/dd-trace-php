--TEST--
Downstream curl requests: an object CURLOPT_POSTFIELDS is recorded as its property table (PHP 7)
--DESCRIPTION--
On PHP 7, ext/curl sends an object CURLOPT_POSTFIELDS as multipart, from its
properties, whatever its __toString(). The hook records a snapshot of the
property table as an array body (the contents, not the object) and never
converts the object to a string (a catchable fatal error in 7.0-7.3, an Error
in 7.4 for an object without __toString(), and a user method call otherwise).
The same transfers are run before rinit() (not analysed) as a
baseline.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
if (PHP_VERSION_ID >= 80000) die('skip PHP 7 only');
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
$helper = ce_helper(array_fill(0, 8, 'ok'));

// dynamic properties only: PHP 7.0's ext/curl asserts on declared ones
class Stringable7 {
    function __toString() { echo "__toString() called\n"; return 'a=b'; }
}

function run($base, $label) {
    $s = new Stringable7;
    $s->field = 'value';
    foreach ([$s, (object)['field' => 'value']] as $o) {
        $ch = curl_init("$base/len");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $o);
        try {
            echo "$label: ", get_class($o), ': ', var_export(curl_exec($ch), true), "\n";
        } catch (\Throwable $e) {
            echo "$label: curl_exec threw ", get_class($e), ': ', $e->getMessage(), "\n";
        }
        $d = datadog\appsec\testing\curl_downstream_dump($ch);
        if ($label === 'analysed') {
            echo "$label: reason: ", var_export($d['reason'], true), "\n";
        }
    }
}

run($base, 'baseline');
var_dump(rinit());
run($base, 'analysed');
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
baseline: Stringable7: 'received %d bytes'
baseline: stdClass: 'received %d bytes'
bool(true)
analysed: Stringable7: 'received %d bytes'
analysed: reason: NULL
analysed: stdClass: 'received %d bytes'
analysed: reason: NULL
bool(true)
curl-1-0 {"server.io.net.request.body":{"field":"value"},"server.io.net.request.headers":{"content-type":["multipart/form-data"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/len"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["%d"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.body":{"field":"value"},"server.io.net.request.headers":{"content-type":["multipart/form-data"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/len"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["%d"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
