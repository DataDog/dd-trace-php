--TEST--
PHP2Shell mitigation (CVE-2026-109618): variables dropped by the input_filter wrapper while a deferred error is pending are not forwarded to the input_filter it captured
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
max_input_vars=10000000
post_max_size=64M
--FILE--
<?php
// p2s_stub.so, loaded after ddappsec with p2s_stub.minit=1, registers a
// non-chaining input_filter in its MINIT (as a shared ext/filter loaded after
// ddappsec would); the wrapper captures it at startup. The stub counts its
// calls for request variables (POST, GET, COOKIE) and prints the count to
// stderr at MSHUTDOWN, which php-cgi still runs after a failed startup.
//
// The request is a startup POST of M small variables, with no query string
// and no cookie. The calibration run (memory_limit=256M) drops nothing, so
// all M reach the captured filter. Then memory_limit = 0.5 x the calibrated
// peak: once it is crossed, the wrapper drops every later variable, and the
// deferred warning reports how many (N). A dropped variable must not reach
// the captured filter, so the stub must count exactly M - N calls.
// Forwarding a variable and then dropping it would give M (and, with
// ext/filter, leave it in filter_input()'s storage).
require __DIR__ . '/inc/php2shell_cgi.php';
const FIELDS = 240000;
$stub = ['p2s_stub.minit' => '1'];
$after = ['ext_after' => true];
$body = '';
for ($i = 0; $i < FIELDS; $i++) {
    $body .= sprintf('f%07d=vvvvvvvv&', $i);
}

function stub_filter_calls(array $r) {
    return preg_match('/P2S_STUB filter=(\d+)/', $r['err'], $m) ? (int)$m[1] : null;
}

$cal = p2s_cgi_run($stub + ['memory_limit' => '256M'], [], [], $body, $after);
$peak = p2s_peak($cal);
echo "calibration: post_fields_eq_fields=", var_export(strpos($cal['out'], ' post_fields=' . FIELDS . ' ') !== false, true),
    " forwarded_eq_fields=", var_export(stub_filter_calls($cal) === FIELDS, true), "\n";
if ($peak === null) {
    p2s_dump($cal, 'calibration');
    exit;
}

$soft_mib = (int)round(0.5 * $peak / P2S_MIB);
$soft = $soft_mib * P2S_MIB;
$r = p2s_cgi_run($stub + ['memory_limit' => "{$soft_mib}M"], [], [], $body, $after);
$outcome = p2s_outcome($r, $soft);
$n = p2s_dropped($r);
$fwd = stub_filter_calls($r);
echo "attack: outcome=$outcome dropped_some=", var_export($n !== null && $n > 0 && $n < FIELDS, true),
    " forwarded_eq_fields_minus_dropped=", var_export($n !== null && $fwd === FIELDS - $n, true), "\n";
if ($outcome !== 'deferred' || $n === null || $fwd !== FIELDS - $n) {
    p2s_dump($r, "peak=$peak soft=$soft n=" . var_export($n, true) . " forwarded=" . var_export($fwd, true));
}
--EXPECT--
calibration: post_fields_eq_fields=true forwarded_eq_fields=true
attack: outcome=deferred dropped_some=true forwarded_eq_fields_minus_dropped=true
