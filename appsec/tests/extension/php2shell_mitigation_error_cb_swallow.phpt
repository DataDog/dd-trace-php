--TEST--
PHP2Shell mitigation (CVE-2026-109618): the deferred fatal ends the request even when a zend_error_cb swallows the E_ERROR
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
// Some agents' zend_error_cb (Pinpoint, for a type error_reporting masks)
// return without chaining, so zend_error(E_ERROR) returns; below PHP 7.4 so
// does zend_error_noreturn(), an alias of it. p2s_stub.so with
// p2s_stub.error_cb_swallow=1 is such a callback. The child raises
// memory_limit to 64M and allocates 70M: the deferred fatal is raised at the
// next VM interrupt, swallowed, and the raise must still bail out, as
// zend_mm_safe_error does: no crash, SCRIPT RAN never printed.
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_runtime_alloc.php';

$r = p2s_cgi_run(['memory_limit' => '16M', 'p2s_stub.error_cb_swallow' => '1'], [],
    p2s_cgi_env(CHILD, 'GET', ['P2S_ML' => '64M', 'P2S_ALLOC' => (string)(70 * P2S_MIB)]),
    null, ['ext_after' => true]);
$all = $r['out'] . $r['err'];
$swallowed = strpos($all, 'P2S_STUB swallowed E_ERROR: ' . p2s_deferred_msg(64 * P2S_MIB)) !== false;
$ran = strpos($r['out'], 'SCRIPT RAN') !== false;
$exited = $r['status'] === 0 || $r['status'] === 255;
echo "swallowed=", var_export($swallowed, true), " script_ran=", var_export($ran, true),
    " exited=", var_export($exited, true), "\n";
if (!$swallowed || $ran || !$exited) {
    p2s_dump($r);
}
--EXPECT--
swallowed=true script_ran=false exited=true
