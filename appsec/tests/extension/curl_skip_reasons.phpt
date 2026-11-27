--TEST--
Downstream curl requests: each skipped transfer writes one debug line naming the reason
--DESCRIPTION--
G3.9 and G10.19: a skipped transfer gets no WAF call and writes exactly one
debug-level line to the AppSec log, "Not observing a curl transfer: <reason>",
for every transfer (not every handle). The line is written without going
through PHP (no error handler call). Transfers that are observed, and those
made outside a request (no reason of G3.9: the skipped metric covers them),
write none.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (!defined('CURLOPT_REQUEST_TARGET')) die('skip CURLOPT_REQUEST_TARGET needed');
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_skip_reasons.log
datadog.appsec.log_level=debug
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
include __DIR__ . '/inc/logging.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'));
set_error_handler(function ($no, $str) {
    echo "error handler: $str\n";
    return true;
});

function transfer($opts, $times = 1)
{
    global $base;
    $ch = curl_init("$base/json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt_array($ch, $opts);
    for ($i = 0; $i < $times; $i++) {
        curl_exec($ch);
    }
}

function lines()
{
    $log = log_contents();
    preg_match_all('/\[debug\] Not observing a curl transfer: (\w+)/', $log, $m);
    truncate_log();
    return implode(',', $m[1]);
}

truncate_log();
// outside a request: not evaluated, and no reason of G3.9
transfer([]);
echo "outside a request: [", lines(), "]\n";
var_dump(rinit());
truncate_log();

transfer([]);
echo "observed: [", lines(), "]\n";
transfer([CURLOPT_URL => 'file:///dev/null']);
echo "scheme: [", lines(), "]\n";
transfer([CURLOPT_URL => $base, CURLOPT_CUSTOMREQUEST => 'CONNECT',
    CURLOPT_REQUEST_TARGET => 'auth.example.test:80']);
echo "connect_method: [", lines(), "]\n";
// only the exact string "CONNECT" skips
foreach (['connect', 'CONNECTX'] as $method) {
    transfer([CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => true]);
    echo "method $method: [", lines(), "]\n";
}
// one line per transfer, not per handle
transfer([CURLOPT_URL => 'file:///dev/null'], 3);
echo "three transfers: [", lines(), "]\n";
// the debug facilities of the app are no reason (G5.5)
transfer([CURLOPT_VERBOSE => true, CURLOPT_STDERR => fopen('php://temp', 'w+')]);
echo "verbose: [", lines(), "]\n";
transfer([CURLINFO_HEADER_OUT => true]);
echo "header_out: [", lines(), "]\n";
restore_error_handler();
var_dump(rshutdown());
// the five observed transfers (also the two with another method, and the
// two with debug options): the pre-send (W1) and the response call each
echo count(ce_execs($helper)), " request_exec call(s)\n";
echo preg_match_all('/\[(warning|error)\]/', log_contents()), " warning(s)\n";
?>
--EXPECT--
outside a request: []
bool(true)
observed: []
scheme: [scheme]
connect_method: [connect_method]
method connect: []
method CONNECTX: []
three transfers: [scheme,scheme,scheme]
verbose: []
header_out: []
bool(true)
10 request_exec call(s)
0 warning(s)
