--TEST--
PHP2Shell mitigation (CVE-2026-109618): with USE_ZEND_ALLOC=0 (no zend_mm accounting) nothing is armed and a body above memory_limit is parsed as without the mitigation
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_cgi();
--INI--
extension=ddtrace.so
--FILE--
<?php
// One urlencoded field whose 12 MiB name alone exceeds memory_limit=4M. With
// post_max_size=0 (no input bound) the hard limit is the cap, memory_limit +
// 1 GiB, so on a zend_mm heap the parse completes and ends in the deferred
// fatal: the window is armed. Under USE_ZEND_ALLOC=0 the heap is a
// malloc()-backed stub: the engine neither accounts for nor enforces
// memory_limit, so nothing is armed and the request runs normally, as it
// would without the mitigation. The mode is forced on, so this also runs on
// patched runtimes.
require __DIR__ . '/inc/php2shell_cgi.php';
$body = 'f' . str_repeat('Z', 12 * P2S_MIB) . '=1';
$soft = 4 * P2S_MIB;

foreach (['1' => 'zend_mm', '0' => 'use_zend_alloc_0'] as $use => $label) {
    $r = p2s_cgi_run(['memory_limit' => '4M', 'post_max_size' => '0'], [], [
        'DD_APPSEC_PHP2SHELL_MITIGATION' => 'on',
        'USE_ZEND_ALLOC' => (string)$use,
    ], $body);
    $outcome = p2s_outcome($r, $soft);
    $lines = p2s_child_lines($r);
    echo "$label: outcome=$outcome post_fields=", $lines ? $lines[0]['post_fields'] : '-',
        " status=", $r['status'], "\n";
}
--EXPECT--
zend_mm: outcome=deferred post_fields=- status=255
use_zend_alloc_0: outcome=ran post_fields=1 status=0
