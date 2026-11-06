--TEST--
PHP2Shell mitigation: a gc_mem_caches() handler replaced before AppSec's startup capture is left alone; one replaced after it is reported in the appsec log only
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
if (PHP_VERSION_ID >= 80400) die('skip: no gc_mem_caches() wrapper from PHP 8.4');
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_unless_stub();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// p2s_stub.gc_mem_caches puts a forwarding gc_mem_caches() handler in place,
// as another extension's wrapper that does not turn use_custom_heap off
// would:
//  - from MINIT (=1), before AppSec's startup capture: ours is not installed
//    (MINFO Not installed, nothing logged), so gc_mem_caches() frees nothing
//    while the request is armed;
//  - from a startup hook after AppSec's capture (=2): the stub's chains to
//    ours, which still frees; MINFO shows Replaced and the appsec log has one
//    line, but nothing reaches the output when that log is PHP error
//    reporting (the default).
// The Datadog profiler built into ddtrace.so is neither case: its wrapper,
// installed before the capture, lives in ddtrace.so, so AppSec's wrapper is
// installed over it and chains to it (the xtest ddtrace.so has no profiler;
// the gradle profiler suite covers that).
require __DIR__ . '/inc/php2shell_cgi.php';
const GC_CHILD = __DIR__ . '/inc/php2shell_child_gc_mem_caches.php';
const MINFO_CHILD = __DIR__ . '/inc/php2shell_child_minfo.php';

$cases = [
    'before' => [['p2s_stub.gc_mem_caches' => '1', 'datadog.appsec.log_file' => 'stderr'], ['ext_after' => true]],
    'after' => [['p2s_stub.gc_mem_caches' => '2', 'datadog.appsec.log_file' => 'stderr'], ['ext_after' => true]],
    'after, default log' => [['p2s_stub.gc_mem_caches' => '2'], ['ext_after' => true]],
];
foreach ($cases as $name => list($ini, $opts)) {
    $ini += ['datadog.appsec.log_level' => 'warning'];
    $gc = p2s_cgi_run($ini, [], p2s_cgi_env(GC_CHILD), null, $opts);
    $freed = preg_match('/^GC freed=(\d+)/m', $gc['out'], $m) ? (int)$m[1] : null;
    $mi = p2s_cgi_run($ini, [], p2s_cgi_env(MINFO_CHILD), null, $opts);
    $row = preg_match('/ gc_mem_caches=(\S+)/', $mi['out'], $m) ? $m[1] : '-';
    $all = $gc['out'] . $gc['err'] . $mi['out'] . $mi['err'];
    $logged = preg_match_all('/gc_mem_caches\(\) was replaced after startup by another extension/', $all);
    $other = preg_match_all('/was replaced after startup/', $all) - $logged;
    echo "$name: freed=", $freed === null ? '-' : ($freed > 0 ? 'positive' : $freed),
        " minfo=$row logged=$logged other=$other\n";
    if ($freed === null || $row === '-') {
        p2s_dump($gc, "$name gc");
        p2s_dump($mi, "$name minfo");
    }
}
--EXPECT--
before: freed=0 minfo=Not_installed logged=0 other=0
after: freed=positive minfo=Replaced logged=2 other=0
after, default log: freed=positive minfo=Replaced logged=0 other=0
