--TEST--
PHP2Shell mitigation (CVE-2026-109618): re-entered from PHP code an inner interrupt handler runs, the mitigation's zend_interrupt_function raises the pending error instead of chaining again
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
// p2s_stub.interrupt=1 stands in for Excimer or pcntl: an inner interrupt
// handler that runs a PHP callback, during which EG(vm_interrupt) is set
// again (a timer or signal firing). Each callback keeps 2 MiB.
//  - idle: no error pending: nested interrupts still chain (depth 3).
//  - pending: memory_limit=16M (hard limit 32 MiB), a 20 MiB allocation
//    crosses it. The callback runs once, then the nested interrupt raises the
//    deferred fatal. Chaining again each time would nest callbacks until the
//    hard limit (exit 137).
//    php-cgi -T 2: in request 2, the first interrupt still runs the callback
//    before the raise (the re-entry flag did not survive the bailout).
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_interrupt_reentry.php';
$soft = 16 * P2S_MIB;
$ini = ['memory_limit' => '16M', 'p2s_stub.interrupt' => '1'];
$opts = ['ext_after' => true];

$r = p2s_cgi_run($ini, [], p2s_cgi_env(CHILD, 'GET', ['P2S_CASE' => 'idle']), null, $opts);
echo "idle: ", preg_match('/^IDLE (.*)$/m', $r['out'], $m) ? $m[1] : 'no IDLE line', "\n";
if (!$m) {
    p2s_dump($r);
}

$r = p2s_cgi_run($ini, ['-T', '2'], p2s_cgi_env(CHILD, 'GET', [
    'P2S_CASE' => 'pending',
    'P2S_ALLOC' => (string)(20 * P2S_MIB),
]), null, $opts);
$outcome = p2s_outcome($r, $soft);
$all = $r['out'] . $r['err'];
$second = strpos($all, 'mitigation deferred this');
$second = $second === false ? false : strpos($all, 'CB depth=1', $second);
echo "pending: outcome=$outcome",
    " cb_depth1=", substr_count($r['out'], 'CB depth=1'),
    " cb_depth2=", substr_count($r['out'], 'CB depth=2'),
    " fatals=", substr_count($all, 'mitigation deferred this'),
    " req2_cb_before_fatal=", var_export($second !== false && strpos($all, 'mitigation deferred this', $second) !== false, true),
    "\n";
if ($outcome !== 'deferred') {
    p2s_dump($r);
}
--EXPECT--
idle: max_depth=3
pending: outcome=deferred cb_depth1=2 cb_depth2=0 fatals=2 req2_cb_before_fatal=true
