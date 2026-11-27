--TEST--
Downstream curl requests: a Stringable CURLOPT_POSTFIELDS is converted once and recorded (PHP 8)
--DESCRIPTION--
The curl_setopt() hook converts a Stringable CURLOPT_POSTFIELDS with
__toString() itself, once, and gives ext/curl the string, which is recorded as
the body (and so is known before the request is sent). An object without
__toString() is passed to ext/curl unchanged: it throws and applies an empty
string, so the transfer is skipped (the options are not known). The same
transfers are run before rinit() (not analysed) as a baseline.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
if (PHP_VERSION_ID < 80000) die('skip PHP 8+');
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

class Counted {
    public $n = 0;
    function __toString(): string { $this->n++; return '{"from":"tostring"}'; }
}

function run($base, $label) {
    $o = new Counted;
    $ch = curl_init("$base/echo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $o);
    $r = curl_exec($ch);
    echo "$label: Stringable: ", var_export($r, true), ", __toString() calls: {$o->n}\n";
    $d = datadog\appsec\testing\curl_downstream_dump($ch);
    if ($label === 'analysed') {
        echo "$label: recorded: ", var_export($d['options']['postfields'], true), "\n";
    }

    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    try {
        var_dump(curl_setopt($ch, CURLOPT_POSTFIELDS, new stdClass));
    } catch (\Throwable $e) {
        echo "$label: curl_setopt threw ", get_class($e), "\n";
    }
    try {
        echo "$label: stdClass: ", var_export(curl_exec($ch), true), "\n";
        $d = datadog\appsec\testing\curl_downstream_dump($ch);
        if ($label === 'analysed') {
            echo "$label: observed: ", var_export($d['observed'], true), ", reason: ",
                var_export($d['reason'], true), "\n";
        }
    } catch (\Throwable $e) {
        echo "$label: curl_exec threw ", get_class($e), ': ', $e->getMessage(), "\n";
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
--EXPECT--
baseline: Stringable: '{"from":"tostring"}', __toString() calls: 1
baseline: curl_setopt threw Error
baseline: stdClass: 'received 0 bytes'
bool(true)
analysed: Stringable: '{"from":"tostring"}', __toString() calls: 1
analysed: recorded: '{"from":"tostring"}'
analysed: curl_setopt threw Error
analysed: stdClass: 'received 0 bytes'
analysed: observed: false, reason: 'unknown_option'
bool(true)
curl-1-0 {"server.io.net.request.body":{"from":"tostring"},"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["19"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
