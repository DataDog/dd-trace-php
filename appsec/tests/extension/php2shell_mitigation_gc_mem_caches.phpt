--TEST--
PHP2Shell mitigation (CVE-2026-109618): gc_mem_caches() frees cached zend_mm memory while the request is armed, and the request stays armed afterwards (a later crossing of memory_limit gets the deferred fatal)
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
// While the request is armed, our handlers occupy the custom-handler slot of
// the heap. Below PHP 8.4, zend_mm_gc() frees nothing on a heap with custom
// handlers, so the mitigation wraps gc_mem_caches() and turns use_custom_heap
// off around the original, then puts it back; from 8.4 on, zend_mm_gc()
// forwards to the custom gc handler, which collects the heap itself.
//
// The php-cgi child (memory_limit=64M) allocates about 20 MB of small strings
// and frees them, then calls gc_mem_caches(), which must report a positive
// amount freed. It then allocates 80M (above the soft limit, below the hard
// limit of 128M): the deferred fatal naming 64 MiB proves our handlers are
// back in effect after the call. Were use_custom_heap left off, zend_mm would
// bypass our handlers while the engine limit is still the sentinel, so the
// allocation would run unchecked (outcome ran, worse than stock PHP's native
// error).
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_gc_mem_caches.php';

$r = p2s_cgi_run(['memory_limit' => '64M'], [], p2s_cgi_env(CHILD, 'GET', [
    'P2S_ALLOC' => (string)(80 * P2S_MIB),
]));
$freed = preg_match('/^GC freed=(\d+)/m', $r['out'], $m) ? (int)$m[1] : null;
$outcome = p2s_outcome($r, 64 * P2S_MIB);
echo "freed_positive=", var_export($freed > 0, true), " outcome=$outcome\n";
if ($freed === null || $freed <= 0 || $outcome !== 'deferred') {
    p2s_dump($r, "freed=" . var_export($freed, true));
}
--EXPECT--
freed_positive=true outcome=deferred
