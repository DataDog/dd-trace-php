--TEST--
Downstream curl requests: a large request under memory pressure is still evaluated, never skipped for memory
--DESCRIPTION--
G3.9 and G9.6: no memory estimate decides whether a transfer is evaluated. A
large header list and body that a 4x estimate would have refused, with a limit
that still holds the real copies, get W1 and the response call, and no skip
line is written.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (memory_get_usage() === 0) die('skip no ZendMM (e.g. ASan)');
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_large_request.log
datadog.appsec.log_level=debug
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_TRACE_ENABLED=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
include __DIR__ . '/inc/logging.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
ce_execs($helper); // request_init
truncate_log();

$lines = [];
for ($i = 0; $i < 40000; $i++) {
    $lines[] = sprintf('X-Header-%05d: %s', $i, 'v');
}
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $lines);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'a=' . str_repeat('x', 1024 * 1024));
// holds the real copies, but not four times the request. The copies are
// mostly one name => list array per header, whose size is that of the engine's
// arrays: peak use above the baseline was measured at 26.4 to 26.6 MB on PHP
// 7.0 to 8.1 and 20.4 MB on 8.3, so 32 MB holds them on every version, while
// four times the request is far above it.
$margin = 32;
ini_set('memory_limit',
    (string)(memory_get_usage(true) + $margin * 1024 * 1024));
var_dump(curl_exec($ch));
ini_set('memory_limit', '128M');
echo preg_match_all('/Not observing a curl transfer/', log_contents()),
    " skip line(s)\n";
var_dump(rshutdown());
// W1 and the response call
echo count(ce_execs($helper)), " request_exec call(s)\n";
?>
--EXPECT--
bool(true)
string(22) "{"status":"ok","n":42}"
0 skip line(s)
bool(true)
2 request_exec call(s)
