--TEST--
PHP2Shell mitigation (CVE-2026-109618): after the deferred fatal, shutdown functions run under a finite memory_limit, max(memory_limit, usage + 8 MiB), also when the usage fits memory_limit; not the configured one below the usage, which some runtimes accept and then do not enforce at all
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// 1. memory_limit=16M and an allocation of 24M (below the 32M hard limit): the
// deferred fatal leaves usage above 16M. php_error_cb() then sets the engine
// limit to PG(memory_limit). On PHP 7.2-7.4.20 and 8.0 the setter accepts a
// limit below the usage and the allocator's check wraps: a shutdown function
// allocating 200M would succeed. On 7.1 the limit would stay at 16M, below
// the usage. Either way the shutdown function must get a memory error at the
// finite limit, above 16M (on a partial runtime it ends the process).
// 2. memory_limit=32M, 26M kept and a temporary copy of it (freed before the
// safe point; a str_repeat() result would not be on PHP 8.4+, which checks for
// a VM interrupt right after the call): the usage fits 32M again at the raise,
// but not with 8 MiB more. The limit is still max(32M, usage + 8 MiB), as when
// the usage does not fit: displaying the error can run an output callback that
// allocates past 32M.
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_runtime_alloc.php';

$cases = [
    '1 above' => [16, ['P2S_ALLOC' => (string)(24 * P2S_MIB)], 24, 48],
    '2 fits' => [32, ['P2S_ALLOC' => (string)(26 * P2S_MIB), 'P2S_ALLOC_COPY' => '1'], 32, 40],
];
foreach ($cases as $label => list($ml, $env, $lo, $hi)) {
    $r = p2s_cgi_run(['memory_limit' => "{$ml}M"], [], p2s_cgi_env(CHILD, 'GET', $env + [
        'P2S_SHUTDOWN_ALLOC' => (string)(200 * P2S_MIB),
    ]));
    $all = $r['out'] . $r['err'];
    $outcome = p2s_outcome($r, $ml * P2S_MIB);
    $ran = strpos($all, 'SHUTDOWN RAN') !== false;
    // Debug builds insert "at file:line" before "(tried".
    preg_match('/Allowed memory size of (\d+) bytes exhausted (?:at \S+ )?\(tried/', $all, $m);
    $native = isset($m[1]) ? (int)$m[1] : 0;
    $finite = $native > $lo * P2S_MIB && $native < $hi * P2S_MIB;
    echo "$label: outcome=$outcome shutdown_ran=", var_export($ran, true),
        " native_at_finite_limit=", var_export($finite, true), "\n";
    if ($outcome !== 'deferred' || $ran || !$finite) {
        echo "native=$native\n";
        p2s_dump($r);
    }
}
--EXPECT--
1 above: outcome=deferred shutdown_ran=false native_at_finite_limit=true
2 fits: outcome=deferred shutdown_ran=false native_at_finite_limit=true
