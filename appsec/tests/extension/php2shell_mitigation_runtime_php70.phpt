--TEST--
PHP2Shell mitigation (CVE-2026-109618): on PHP 7.0 only the parse windows are armed (no VM interrupt to deliver a deferred error), so a runtime parse_str() gets the engine's own error
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
if (PHP_VERSION_ID >= 70100) {
    die("skip: PHP 7.0 only");
}
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// PHP 7.0 has no EG(vm_interrupt) nor zend_interrupt_function, so a pending
// error could not be delivered after RINIT: the request is not armed there.
// The counterpart of php2shell_mitigation_runtime_arming.phpt (same child and
// calibration): the mitigation is active, yet the crossing inside parse_str()
// gets the engine's own error and no mitigation log line.
require __DIR__ . '/inc/php2shell_cgi.php';
$env = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_runtime_parse_str.php');
$cal = p2s_cgi_run(['memory_limit' => '256M'], [], $env);
if (!preg_match('/PARSED fields=(\d+) base=(\d+) peak=(\d+)/', $cal['out'], $m)) {
    p2s_dump($cal, 'calibration failed');
    exit;
}
$base = (int)$m[2];
$peak = (int)$m[3];
$soft_mib = (int)ceil(($base + 0.6 * ($peak - $base)) / P2S_MIB);
$soft = $soft_mib * P2S_MIB;
echo "soft_between_base_and_peak=", var_export($base < $soft && $soft < $peak, true), "\n";

$r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], $env);
$outcome = p2s_outcome($r, $soft);
echo "outcome=$outcome\n";
echo "mitigation_log=", var_export(strpos($r['out'] . $r['err'], 'PHP2Shell mitigation:') !== false, true), "\n";
if ($outcome !== 'native') {
    p2s_dump($r, "base=$base peak=$peak soft=$soft");
}
--EXPECT--
soft_between_base_and_peak=true
outcome=native
mitigation_log=false
