--TEST--
PHP2Shell mitigation (CVE-2026-109618): an error pending inside a tracer hook closure is raised after it, not at a VM interrupt inside the tracer's sandbox, which would swallow it
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
// A hook's begin closure crosses memory_limit=16M (usage about 22 MiB, below
// the 32 MiB hard limit) and then reaches VM safe points. The tracer runs the
// closure in its sandbox (EH_THROW), which swallows a bailout: raised there,
// the error would be lost and the script would run on. The closure completes
// and the deferred fatal comes after the hooked call, before SCRIPT RAN.
require __DIR__ . '/inc/php2shell_cgi.php';
$soft = 16 * P2S_MIB;
$r = p2s_cgi_run(['memory_limit' => '16M'], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_hook_sandbox.php', 'GET', [
        'P2S_ALLOC' => (string)(20 * P2S_MIB),
    ]));
$outcome = p2s_outcome($r, $soft);
$all = $r['out'] . $r['err'];
$closure = strpos($all, 'CLOSURE DONE');
$fatal = strpos($all, 'Allowed memory size');
echo "closure_done=", var_export($closure !== false, true),
    " fatal_after_closure=", var_export($closure !== false && $fatal !== false && $closure < $fatal, true),
    " outcome=$outcome\n";
if ($outcome !== 'deferred' || $closure === false) {
    p2s_dump($r);
}
--EXPECT--
closure_done=true fatal_after_closure=true outcome=deferred
