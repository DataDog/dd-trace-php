--TEST--
PHP2Shell mitigation (CVE-2026-109618): runtime parse_str() is armed, so a parse that crosses the memory_limit set at startup gets the deferred fatal at the next VM interrupt (phase:runtime)
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// parse_str() reaches the same insertion as the request-body parse, but it
// runs after RINIT. Whole-request arming (PHP 7.1+) keeps the memory manager
// armed until appsec's RSHUTDOWN, so the insertion runs to completion past
// memory_limit, the error is left pending and raised at the child's safe
// point: PARSED is never printed and the log line carries phase:runtime.
// php2shell_mitigation_runtime_arming.phpt covers a limit raised with
// ini_set() from -1; here the limit is the one php-cgi starts with, which also
// bounded the startup window. On PHP 7.0 the engine raises its own error
// (php2shell_mitigation_runtime_php70.phpt). A single key is used: a bailout
// while it is being accumulated leaves no half-built array behind.
//
// Calibrate what parsing a string with one 4 MB key needs above the usage
// before the call, then re-run with memory_limit part way into it.
require __DIR__ . '/inc/php2shell_cgi.php';
$env = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_runtime_parse_str.php');

$cal = p2s_cgi_run(['memory_limit' => '256M'], [], $env);
if (!preg_match('/PARSED fields=(\d+) base=(\d+) peak=(\d+)/', $cal['out'], $m)) {
    p2s_dump($cal, 'calibration failed');
    exit;
}
echo "calibration_fields=", $m[1], "\n";
$base = (int)$m[2];
$peak = (int)$m[3];
$soft_mib = (int)ceil(($base + 0.6 * ($peak - $base)) / P2S_MIB);
$soft = $soft_mib * P2S_MIB;
echo "soft_between_base_and_peak=", var_export($base < $soft && $soft < $peak, true), "\n";
echo "peak_below_hard=", var_export($peak < p2s_request_hard_limit($soft), true), "\n";

$r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], $env);
$all = $r['out'] . $r['err'];
$outcome = p2s_outcome($r, $soft);
echo "outcome=$outcome\n";
echo "parsed=", var_export(strpos($r['out'], 'PARSED') !== false, true), "\n";
echo "phase_runtime=", var_export(strpos($all, 'phase:runtime') !== false, true), "\n";
if ($outcome !== 'deferred') {
    p2s_dump($r, "base=$base peak=$peak soft=$soft");
}
--EXPECT--
calibration_fields=1
soft_between_base_and_peak=true
peak_below_hard=true
outcome=deferred
parsed=false
phase_runtime=true
