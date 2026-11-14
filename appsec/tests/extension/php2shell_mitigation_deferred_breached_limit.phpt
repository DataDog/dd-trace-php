--TEST--
PHP2Shell mitigation (CVE-2026-109618): the deferred fatal and its log line name the memory_limit that was exceeded, even when memory_limit changed before the raise
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// A tracer hook's begin closure crosses memory_limit=16M; the raise is refused
// inside the tracer's sandbox. The closure then raises memory_limit to 1G
// (as AppSec's shutdown raise to ZEND_LONG_MAX would) before the error is
// raised after the hooked call. Both must still name 16 MiB.
require __DIR__ . '/inc/php2shell_cgi.php';
$soft = 16 * P2S_MIB;
$r = p2s_cgi_run(['memory_limit' => '16M'], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_hook_sandbox.php', 'GET', [
        'P2S_ALLOC' => (string)(20 * P2S_MIB),
        'P2S_ML' => '1G',
    ]));
$all = $r['out'] . $r['err'];
$outcome = p2s_outcome($r, $soft);
$log = preg_match('/exceeded memory_limit \((\d+) bytes\)/', $all, $m) ? $m[1] : 'none';
echo "closure_done=", var_export(strpos($all, 'CLOSURE DONE') !== false, true),
    " outcome=$outcome log_limit_ok=", var_export($log === (string)$soft, true), "\n";
if ($outcome !== 'deferred' || $log !== (string)$soft) {
    p2s_dump($r);
}
--EXPECT--
closure_done=true outcome=deferred log_limit_ok=true
