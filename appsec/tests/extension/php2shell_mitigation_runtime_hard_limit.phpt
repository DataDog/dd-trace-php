--TEST--
PHP2Shell mitigation (CVE-2026-109618): after RINIT the hard limit is max(L, memory_limit + 8 MiB), with L upstream's limit (2 x memory_limit, or memory_limit + 1 GiB from 1 GiB): an allocation below it gets the deferred fatal, one above it ends the process; a memory_limit of 2G gives 3 GiB
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
// One allocation after RINIT, then a VM safe point. With S the soft limit
// (memory_limit), the hard limit outside the windows is max(L, S + 8 MiB),
// with L upstream's limit: 2S below 1 GiB, S + 1 GiB from 1 GiB.
//  - S = 32M, 56M: usage lands between 1.5S (48M) and 2S (64M). The
//    allocation succeeds, the error is left pending and raised at the safe
//    point (outcome deferred).
//  - S = 32M, 72M: usage would land above 2S. The pre-check for a fresh huge
//    allocation ends the process with _exit(137) before the heap grows
//    (outcome hard).
//  - S = 4M, 6.5M: usage lands above 2S (8M) but below S + 8 MiB (12M):
//    a small memory_limit gets the additive margin, so the first chunk past
//    S is deferred, not 137.
// base_ok: the usage before the allocation plus the allocation lands in the
// band [lo, hi) MiB named for each run.
// Then, with no large allocation: memory_limit=32M raised with ini_set() to
// 2G. From 1 GiB upstream's L is S + 1 GiB, so the request's hard limit
// becomes 3 GiB, not 2S (4 GiB).
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_runtime_alloc.php';

$runs = [
    // memory_limit, alloc MiB, expected outcome, band lo, band hi (MiB)
    ['32M', 56, 'deferred', 48, 64],
    ['32M', 72, 'hard', 64, PHP_INT_MAX],
    ['4M', 6.5, 'deferred', 8, 12],
];
foreach ($runs as list($ml, $mib, $want, $lo, $hi)) {
    $r = p2s_cgi_run(['memory_limit' => $ml], [], p2s_cgi_env(CHILD, 'GET', [
        'P2S_ALLOC' => (string)(int)($mib * P2S_MIB),
    ]));
    $base = preg_match('/^BASE usage=(\d+)/m', $r['out'], $m) ? (int)$m[1] : null;
    $land = $base === null ? null : ($base / P2S_MIB) + $mib;
    $base_ok = $land !== null && $land > $lo && $land < $hi;
    $outcome = p2s_outcome($r, (int)$ml * P2S_MIB);
    echo "memory_limit=$ml alloc={$mib}M base_ok=", var_export($base_ok, true), " outcome=$outcome",
        $outcome === 'hard' ? " status={$r['status']}" : '', "\n";
    if ($outcome !== $want || !$base_ok) {
        p2s_dump($r, "base=" . var_export($base, true));
    }
}
$r = p2s_cgi_run(['memory_limit' => '32M'], [], p2s_cgi_env(CHILD, 'GET', [
    'P2S_ML' => '2G',
    'P2S_REQUEST_STATUS' => '1',
]));
if (preg_match('/^REQUEST_STATUS armed=(\w+) hard=(-?\d+)/m', $r['out'], $m)) {
    echo "raised to 2G: armed=$m[1] hard_limit=$m[2]\n";
} else {
    p2s_dump($r, 'no REQUEST_STATUS line');
}
--EXPECT--
memory_limit=32M alloc=56M base_ok=true outcome=deferred
memory_limit=32M alloc=72M base_ok=true outcome=hard status=137
memory_limit=4M alloc=6.5M base_ok=true outcome=deferred
raised to 2G: armed=true hard_limit=3221225472
