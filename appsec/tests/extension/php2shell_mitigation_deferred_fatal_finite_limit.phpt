--TEST--
PHP2Shell mitigation (CVE-2026-109618): after the deferred fatal the request stays armed, so shutdown functions have 8 MiB above the usage at the raise before the next deferred fatal, and an allocation past the hard limit, max(request hard limit, that floor + 8 MiB), ends in _exit(137), as upstream's hard limit does
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
// deferred fatal leaves usage U above 16M. The soft limit becomes F = U +
// 8 MiB and the hard limit F + 8 MiB, above 32M: a shutdown function
// allocating 4M runs; one allocating 200M ends the process at that hard
// limit, before the allocation reaches the OS. Unarmed, php_error_cb() would
// set the engine limit to PG(memory_limit), which some runtimes accept below
// the usage and then do not enforce at all.
// 2. memory_limit=32M, 26M kept and a temporary copy of it (freed before the
// safe point; a str_repeat() result would not be on PHP 8.4+, which checks for
// a VM interrupt right after the call): F = U + 8 MiB is below the request
// hard limit of 32M (64M), which stays the hard limit.
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_runtime_alloc.php';

$cases = [
    '1 above, 4M' => [16, ['P2S_ALLOC' => (string)(24 * P2S_MIB)], 4],
    '1 above, 200M' => [16, ['P2S_ALLOC' => (string)(24 * P2S_MIB)], 200],
    '2 fits, 200M' => [32, ['P2S_ALLOC' => (string)(26 * P2S_MIB), 'P2S_ALLOC_COPY' => '1'], 200],
];
foreach ($cases as $label => list($ml, $env, $shutdown)) {
    $r = p2s_cgi_run(['memory_limit' => "{$ml}M"], [], p2s_cgi_env(CHILD, 'GET', $env + [
        'P2S_SHUTDOWN_ALLOC' => (string)($shutdown * P2S_MIB),
    ]));
    $all = $r['out'] . $r['err'];
    $deferred = strpos($all, p2s_deferred_msg($ml * P2S_MIB)) !== false;
    $ran = strpos($all, 'SHUTDOWN RAN') !== false;
    $hard = preg_match('/hard memory limit of (\d+) bytes exhausted/', $all, $m) ? (int)$m[1] : 0;
    $request_hard = p2s_request_hard_limit($ml * P2S_MIB);
    $cap = $hard === 0 ? 'none' : ($hard === $request_hard ? 'request' : ($hard > $request_hard ? 'F+8M' : 'below'));
    echo "$label: deferred=", var_export($deferred, true), " shutdown_ran=", var_export($ran, true),
        " status={$r['status']} hard_limit=$cap native=", var_export(p2s_native_oom($r), true), "\n";
    if (!$deferred || ($shutdown === 4) !== $ran || ($ran ? $hard !== 0 : $r['status'] !== 137)) {
        echo "hard=$hard\n";
        p2s_dump($r);
    }
}
--EXPECT--
1 above, 4M: deferred=true shutdown_ran=true status=255 hard_limit=none native=false
1 above, 200M: deferred=true shutdown_ran=false status=137 hard_limit=F+8M native=false
2 fits, 200M: deferred=true shutdown_ran=false status=137 hard_limit=request native=false
