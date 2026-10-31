--TEST--
PHP2Shell mitigation (CVE-2026-109618): a body parsed by request_parse_body() (PHP 8.4+) is also deferred to a clean post-parse fatal, with memory_limit calibrated from the measured peak
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
post_max_size=32M
--FILE--
<?php
// Same calibration as php2shell_mitigation_oom_cgi.phpt, but the body is sent
// with PUT, so request startup leaves it alone and the child parses it with
// request_parse_body(). That function never calls sapi_module.treat_data, so
// only its own hook arms the window here. Calibrate the peak of the parse
// with a generous limit, then re-run with memory_limit = 0.7 x peak, which puts
// the peak in the middle of the deferred band (0.7P < P <= 1.4P). The deferred
// fatal ends the script: nothing after request_parse_body() runs.
require __DIR__ . '/inc/php2shell_cgi.php';
$body = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$put = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_rpb.php', 'PUT');

$peak = p2s_calibrate([], $put, $body);
$soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
$soft = $soft_mib * P2S_MIB;
echo "peak_in_deferred_band=", var_export($peak > $soft && $peak <= 2 * $soft, true), "\n";

$r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], $put, $body);
$outcome = p2s_outcome($r, $soft);
echo "startup_left_body=", var_export(strpos($r['out'], 'BEFORE PARSE post_fields=0') !== false, true), "\n";
echo "outcome=$outcome\n";
// p2s_outcome() reports 'deferred' before it looks for SCRIPT RAN, so check
// separately that the child stopped at request_parse_body().
$continued = strpos($r['out'], 'SCRIPT RAN') !== false;
echo "continued=", var_export($continued, true), "\n";
if ($outcome !== 'deferred' || $continued) {
    p2s_dump($r, "peak=$peak soft=$soft");
}
--EXPECT--
peak_in_deferred_band=true
startup_left_body=true
outcome=deferred
continued=false
