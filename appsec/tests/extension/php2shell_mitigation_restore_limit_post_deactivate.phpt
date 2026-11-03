--TEST--
PHP2Shell mitigation (CVE-2026-109618): when AppSec's RSHUTDOWN is skipped and post_deactivate drops a pending error with usage above memory_limit, the engine gets a finite limit (usage + 8 MiB), not the sentinel or a wrapped limit
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
if (PHP_VERSION_ID >= 80100) {
    die("skip: from PHP 8.1 a bailout in one RSHUTDOWN does not skip AppSec's");
}
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_stub();
p2s_skip_unless_stub_runs_after_ddtrace();
--FILE--
<?php
// memory_limit=16M. The script holds one chunk with p2s_stub_hold_chunks(1).
// p2s_stub.so, loaded after ddtrace.so, runs its RSHUTDOWN first: it frees
// the chunk, pads real usage to 20M (above the soft limit, below the hard
// limit: a pending error, with no later safe point) and bails out. Before PHP
// 8.1 one zend_try covers every RSHUTDOWN, so AppSec's (and AppSec's request
// shutdown, which also restores memory_limit) is skipped. AppSec's
// post_deactivate then disarms with usage above 16M: restoring 16M fails
// (7.4.21+: the sentinel stays) or is accepted below usage (earlier; on 8.0
// the allocator's check then wraps).
// It must set usage + 8 MiB instead: the stub's 64M allocation in its
// sapi_module.deactivate wrapper, after every post_deactivate, must fail. On
// a partial runtime (minimal fix of the EOL branches) the engine's memory
// error ends the process with _exit(1) before the stub can report the
// bailout.
// With the tracer disabled (datadog.trace.enabled=0): its RSHUTDOWN is skipped
// too (see php2shell_mitigation_lifecycle_fallbacks.phpt).
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_cached_chunks.php';

putenv('TEST_PHP_EXTRA_ARGS=' . getenv('TEST_PHP_EXTRA_ARGS') . ' -d datadog.trace.enabled=0');
$r = p2s_cgi_run(['memory_limit' => '16M', 'p2s_stub.rshutdown_pad' => (string)(20 * P2S_MIB),
        'p2s_stub.rshutdown_bailout' => '1', 'p2s_stub.sapi_deactivate_alloc' => (string)(64 * P2S_MIB)], [],
    p2s_cgi_env(CHILD, 'GET', ['P2S_CHUNKS' => '1']), null, ['ext_after' => true]);
$all = $r['out'] . $r['err'];
$ran = substr_count($r['out'], 'SCRIPT RAN');
$usage = preg_match('/P2S_STUB RSHUTDOWN usage=(\d+)/', $all, $m) ? (int)$m[1] : 0;
$bailed = preg_match('/P2S_STUB sapi_deactivate alloc bailed=(\d)/', $all, $m) ? $m[1] : 'none';
$padded = $usage > 16 * P2S_MIB && $usage <= 21 * P2S_MIB;
// Debug builds insert "at file:line" before "(tried".
$limit = preg_match('/Allowed memory size of (\d+) bytes exhausted (at \S+ )?\(tried to allocate '
    . (64 * P2S_MIB) . ' bytes\)/', $all, $m) ? (int)$m[1] : 0;
$finite = $limit > $usage && $limit <= $usage + 9 * P2S_MIB;
if (p2s_runtime_kind() === 'partial') {
    $bounded = $bailed === 'none' && $r['status'] === 1 && $finite;
} else {
    $bounded = $bailed === '1' && $finite;
}
echo "ran=$ran deferred=", var_export(strpos($all, 'PHP2Shell mitigation deferred') !== false, true),
    " padded=", var_export($padded, true), " sapi_deactivate_bounded=", var_export($bounded, true), "\n";
if ($ran !== 1 || !$padded || !$bounded) {
    p2s_dump($r);
}
--EXPECT--
ran=1 deferred=false padded=true sapi_deactivate_bounded=true
