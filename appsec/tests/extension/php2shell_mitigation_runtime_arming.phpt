--TEST--
PHP2Shell mitigation (CVE-2026-109618): appsec's RINIT arms the request, so a runtime parse_str() that crosses a memory_limit set with ini_set() gets the deferred fatal at the next VM interrupt (phase:runtime); not with appsec disabled for the request; a startup-parse deferral reports phase:startup
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
post_max_size=32M
--FILE--
<?php
// Whole-request arming is what the mitigation does whenever it is active (PHP
// 7.1+): the Zend MM handlers are installed at the end of appsec's RINIT and
// removed at the start of its RSHUTDOWN.
//
// 1. php-cgi runs as a FastCGI responder with memory_limit=-1, so no startup
//    window arms (no finite limit) and only the RINIT install can arm the
//    request. The child raises memory_limit with ini_set() (the memory_limit
//    hook makes it the soft limit) and calls parse_str() on a string with one
//    4 MB key. Calibrate what the parse needs above the usage before the
//    call, then re-run with the limit part way into it. The insertion runs to
//    completion, the error is left pending and raised at the child's safe
//    point: PARSED is never printed, the fatal names the ini_set() value, and
//    the log line carries phase:runtime. peak_below_hard shows the peak stays
//    below the hard limit outside the windows, max(L, S + 8 MiB) with L
//    upstream's limit (2S below 1 GiB, S + 1 GiB from 1 GiB).
// 2. The same request with DD_APPSEC_ENABLED=0 as a FastCGI param (as a
//    php-fpm pool env[] directive would set it): appsec is fully disabled for
//    the request, nothing is armed at RINIT, and the engine raises its own
//    error inside parse_str().
// 3. A urlencoded POST body crossing memory_limit in the startup parse (as in
//    php2shell_mitigation_oom_cgi.phpt): still the deferred fatal, now with
//    phase:startup.
require __DIR__ . '/inc/php2shell_cgi.php';
require __DIR__ . '/inc/php2shell_fcgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_runtime_parse_str.php';
$ini = ['memory_limit' => '-1'];
$params = ['SCRIPT_FILENAME' => CHILD];

$cal = p2s_fcgi_run($ini, [], $params);
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

$r = p2s_fcgi_run($ini, [], $params + ['P2S_ML' => "{$soft_mib}M"]);
$all = $r['out'] . $r['err'];
$outcome = p2s_outcome($r, $soft);
echo "runtime: outcome=$outcome",
    " parsed=", var_export(strpos($r['out'], 'PARSED') !== false, true),
    " logged=", var_export(strpos($all, 'PHP2Shell mitigation:') !== false, true),
    " phase_runtime=", var_export(strpos($all, 'phase:runtime') !== false, true), "\n";
if ($outcome !== 'deferred') {
    p2s_dump($r, "base=$base peak=$peak soft=$soft");
}

$r = p2s_fcgi_run($ini, [], $params + ['P2S_ML' => "{$soft_mib}M", 'DD_APPSEC_ENABLED' => '0']);
$outcome = p2s_outcome($r, $soft);
echo "appsec disabled for the request: outcome=$outcome",
    " phase_runtime=", var_export(strpos($r['out'] . $r['err'], 'phase:runtime') !== false, true), "\n";
if ($outcome !== 'native') {
    p2s_dump($r, "soft=$soft");
}

$body = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$peak = p2s_calibrate([], [], $body);
$soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
$soft = $soft_mib * P2S_MIB;
$r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], [], $body);
$all = $r['out'] . $r['err'];
$outcome = p2s_outcome($r, $soft);
echo "startup parse: outcome=$outcome",
    " phase_startup=", var_export(strpos($all, 'phase:startup') !== false, true),
    " phase_runtime=", var_export(strpos($all, 'phase:runtime') !== false, true), "\n";
if ($outcome !== 'deferred') {
    p2s_dump($r, "peak=$peak soft=$soft");
}
--EXPECT--
calibration_fields=1
soft_between_base_and_peak=true
peak_below_hard=true
runtime: outcome=deferred parsed=false logged=true phase_runtime=true
appsec disabled for the request: outcome=native phase_runtime=false
startup parse: outcome=deferred phase_startup=true phase_runtime=false
