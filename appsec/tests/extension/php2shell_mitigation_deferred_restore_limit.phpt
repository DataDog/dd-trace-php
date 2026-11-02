--TEST--
PHP2Shell mitigation (CVE-2026-109618): after a deferred runtime fatal, AppSec's request shutdown falls back to a finite memory_limit (usage + 8 MiB) instead of failing to restore it, so no "Failed to restore memory limit" line is shown and a later RSHUTDOWN allocating past it still gets a memory error
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_stub();
--FILE--
<?php
// memory_limit=16M and an allocation of 24M (below the 32M hard limit): the
// deferred fatal leaves usage above 16M. AppSec's request shutdown restores
// memory_limit after lifting it; 16M no longer fits, so it sets usage + 8 MiB
// and logs at debug only (display_errors=1 would show an error-level line).
// p2s_stub.so, loaded before ddtrace.so, then allocates 64M in its RSHUTDOWN,
// which runs after AppSec's: that must still fail with a memory error (on
// a partial runtime the engine's error ends the process before the stub can
// report it).
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_runtime_alloc.php';

$r = p2s_cgi_run(['memory_limit' => '16M', 'p2s_stub.rshutdown_alloc' => (string)(64 * P2S_MIB)], [],
    p2s_cgi_env(CHILD, 'GET', ['P2S_ALLOC' => (string)(24 * P2S_MIB)]), null, ['ext_before' => true]);
$all = $r['out'] . $r['err'];
$outcome = p2s_outcome($r, 16 * P2S_MIB);
$restore_line = strpos($all, 'Failed to restore memory limit') !== false;
$bailed = preg_match('/P2S_STUB RSHUTDOWN alloc bailed=(\d)/', $all, $m) ? $m[1] : 'none';
// Debug builds insert "at file:line" before "(tried".
$native = preg_match('/Allowed memory size of \d+ bytes exhausted (at \S+ )?\(tried to allocate/', $all);
$bounded = $bailed === '1' || ($bailed === 'none' && $native);
echo "outcome=$outcome restore_line=", var_export($restore_line, true),
    " rshutdown_alloc_bounded=", var_export($bounded, true), "\n";
if ($outcome !== 'deferred' || $restore_line || !$bounded) {
    p2s_dump($r);
}
--EXPECT--
outcome=deferred restore_line=false rshutdown_alloc_bounded=true
