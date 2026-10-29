--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): a startup-parse crossing of memory_limit under each mode and WAF decision: deferred when armed (pending, block, mode on); the engine's own error under none, monitor, off and a patched runtime
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
post_max_size=32M
--FILE--
<?php
// A urlencoded POST body whose parse crosses memory_limit, as in
// php2shell_mitigation_oom_cgi.phpt, in one php-cgi child per row of the
// behaviour table. The decision is the testing override the process starts
// with, so it is in force at the startup parse of its first request (as the
// decision of an earlier request would be); the runtime is forced to full,
// except in the patched row. Calibrate the parse's peak with a generous
// limit, then run with memory_limit = 0.7 x peak:
//  - armed (pending, block, mode on whatever the decision): the deferred fatal.
//  - none, monitor, off, patched: the parse window is not armed, so the
//    engine's error. Under monitor it is detected, but there is no span yet
//    (before RINIT): it is logged at most, and the child exits normally.
require __DIR__ . '/inc/php2shell_cgi.php';
$body = 'f' . str_repeat('Z', 2000000) . '[k]=1';
$peak = p2s_calibrate([], p2s_waf_env('off'), $body);
$soft_mib = max(4, (int)round($peak * 0.7 / P2S_MIB));
$soft = $soft_mib * P2S_MIB;

$rows = [
    // label, mode, decision, runtime
    ['off', 'off', null, 'full'],
    ['auto, patched', 'auto', null, 'patched'],
    ['auto, pending', 'auto', null, 'full'],
    ['auto, none', 'auto', 'none', 'full'],
    ['auto, monitor', 'auto', 'monitor', 'full'],
    ['auto, block', 'auto', 'block', 'full'],
    ['on, none ignored', 'on', 'none', 'full'],
];
foreach ($rows as list($label, $mode, $decision, $runtime)) {
    $r = p2s_cgi_run(['memory_limit' => "{$soft_mib}M"], [], p2s_waf_env($mode, $decision, $runtime), $body);
    $outcome = p2s_outcome($r, $soft);
    // A fatal error exits with 255; a crash with another status (or leaves a
    // sanitizer report).
    $clean = in_array($r['status'], [0, 255], true) && strpos($r['out'] . $r['err'], 'Sanitizer') === false;
    echo "$label: outcome=$outcome clean_exit=", var_export($clean, true), "\n";
    if (!$clean || !in_array($outcome, ['deferred', 'native'], true)) {
        p2s_dump($r, "peak=$peak soft=$soft");
    }
}
--EXPECT--
off: outcome=native clean_exit=true
auto, patched: outcome=native clean_exit=true
auto, pending: outcome=deferred clean_exit=true
auto, none: outcome=native clean_exit=true
auto, monitor: outcome=native clean_exit=true
auto, block: outcome=deferred clean_exit=true
on, none ignored: outcome=deferred clean_exit=true
