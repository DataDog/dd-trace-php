--TEST--
PHP2Shell mitigation (CVE-2026-109618): an error pending inside a tracer hook closure whose allocation outlives it is still raised after it: with usage above memory_limit, later allocations keep asking for the interrupt
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// As php2shell_mitigation_hook_sandbox.phpt, but the closure keeps its
// allocation in a global, so usage stays above memory_limit=16M after the
// hooked call returns. The interrupt refused inside the sandbox must still be
// asked for again by the allocations that follow, and the deferred fatal comes
// before SCRIPT RAN.
require __DIR__ . '/inc/php2shell_cgi.php';
$soft = 16 * P2S_MIB;
$r = p2s_cgi_run(['memory_limit' => '16M'], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_hook_sandbox.php', 'GET', [
        'P2S_ALLOC' => (string)(20 * P2S_MIB),
        'P2S_KEEP' => '1',
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
