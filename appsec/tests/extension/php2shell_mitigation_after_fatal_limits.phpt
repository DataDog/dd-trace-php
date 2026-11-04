--TEST--
PHP2Shell mitigation (CVE-2026-109618): the limits after a deferred fatal: soft max(memory_limit, F) and hard C = max(request hard limit, F + 8 MiB), with F = max(memory_limit, usage at the raise + 8 MiB); C follows memory_limit changes, and a later raise moves F up to C - 8 MiB only
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// memory_limit=16M; the main script allocates 15 MiB: first deferred fatal,
// with usage U above 16M. A shutdown function prints the request-level limits
// (php2shell_mitigation_request_status()):
//   after: F = U + 8 MiB (above 16M) and C = F + 8 MiB (above 32M, the
//          request hard limit of 16M);
//   64M:   C = 128M, the request hard limit, and the soft limit 64M;
//   24M:   C = 48M, the request hard limit (above F + 8 MiB), the soft limit F
//          again;
//   grown: the usage grows to 34 MiB, above F: second deferred fatal, with
//          U + 8 MiB above C - 8 MiB, so F = C - 8 MiB = 40M and C stays 48M
//          (printed by an output callback, after the raise).
require __DIR__ . '/inc/php2shell_cgi.php';
$r = p2s_cgi_run(['memory_limit' => '16M', 'datadog.appsec.log_file' => 'stderr'], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_after_fatal.php', 'GET', [
        'P2S_FATAL' => 'alloc', 'P2S_AFTER' => 'shutdown', 'P2S_ALLOC' => (string)(15 * P2S_MIB),
        'P2S_LIMITS' => '64M,24M', 'P2S_GROW' => (string)(34 * P2S_MIB),
    ]));
preg_match_all('/^LIMITS (\S+) armed=(\w+) soft=(-?\d+) hard=(-?\d+) usage=(\d+)$/m', $r['err'], $m, PREG_SET_ORDER);
$l = [];
foreach ($m as $line) {
    $l[$line[1]] = ['armed' => $line[2], 'soft' => (int)$line[3], 'hard' => (int)$line[4], 'usage' => (int)$line[5]];
}
$mib = P2S_MIB;
$ok = count($l) === 4;
if ($ok) {
    $f = $l['after']['soft'];
    echo "after: armed={$l['after']['armed']} soft_above_16M=", var_export($f > 16 * $mib, true),
        " hard=soft+8M:", var_export($l['after']['hard'] === $f + 8 * $mib, true), "\n";
    foreach (['64M' => 64, '24M' => 24] as $k => $ml) {
        echo "$k: armed={$l[$k]['armed']} soft=", $l[$k]['soft'] / $mib, "M (", $l[$k]['soft'] === max($ml * $mib, $f) ? 'max(ml, F)' : 'other',
            ") hard=", $l[$k]['hard'] / $mib, "M\n";
    }
    echo "grown: armed={$l['grown']['armed']} soft=", $l['grown']['soft'] / $mib, "M hard=", $l['grown']['hard'] / $mib, "M\n";
}
echo "raises=", p2s_raises($r), " native=", var_export(p2s_native_oom($r), true), "\n";
if (!$ok) {
    p2s_dump($r);
}
--EXPECTF--
after: armed=true soft_above_16M=true hard=soft+8M:true
64M: armed=true soft=64M (max(ml, F)) hard=128M
24M: armed=true soft=%sM (max(ml, F)) hard=48M
grown: armed=true soft=40M hard=48M
raises=2 native=false
