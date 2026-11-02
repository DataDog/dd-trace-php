--TEST--
PHP2Shell mitigation (CVE-2026-109618): after a deferred runtime fatal, AppSec's request shutdown still sets a finite memory_limit when memory_limit fits only once cached chunks are freed (PHP 8.1.4+ frees them without writing the limit)
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_stub();
--FILE--
<?php
// PHP 8.1+: below, php_error_cb() restores memory_limit below usage after
// the deferred fatal, so the stub's padding would fail.
// memory_limit=16M and 10 chunks held by p2s_stub: deferred fatal. The stub's
// RSHUTDOWN, before AppSec's, frees them (one chunk stays cached) and pads
// real usage to 17M, so restoring 16M fits only by freeing the cache. The
// engine must not keep the lifted limit: the stub's 64M allocation in
// post_deactivate (after AppSec's) must fail.
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_cached_chunks.php';

$r = p2s_cgi_run(['memory_limit' => '16M', 'p2s_stub.rshutdown_pad' => (string)(17 * P2S_MIB),
        'p2s_stub.post_deactivate_alloc' => (string)(64 * P2S_MIB)], [],
    p2s_cgi_env(CHILD, 'GET', ['P2S_CHUNKS' => '10']), null, ['ext_after' => true]);
$all = $r['out'] . $r['err'];
$outcome = p2s_outcome($r, 16 * P2S_MIB);
$usage = preg_match('/P2S_STUB RSHUTDOWN usage=(\d+)/', $all, $m) ? (int)$m[1] : 0;
$bailed = preg_match('/P2S_STUB post_deactivate alloc bailed=(\d)/', $all, $m) ? $m[1] : 'none';
$padded = $usage > 16 * P2S_MIB && $usage <= 18 * P2S_MIB;
echo "outcome=$outcome padded=", var_export($padded, true), " post_deactivate_bailed=$bailed\n";
if ($outcome !== 'deferred' || !$padded || $bailed !== '1') {
    p2s_dump($r);
}
--EXPECT--
outcome=deferred padded=true post_deactivate_bailed=1
