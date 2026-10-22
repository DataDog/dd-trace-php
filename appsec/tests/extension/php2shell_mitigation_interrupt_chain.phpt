--TEST--
PHP2Shell mitigation (CVE-2026-109618): the mitigation's zend_interrupt_function chains to pcntl's, with no error pending and with one, and runs it before raising the pending error
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_cgi_ext('pcntl');
p2s_skip_unless_cgi_ext('posix');
--INI--
extension=ddtrace.so
--FILE--
<?php
// The mitigation installs its zend_interrupt_function outermost, over pcntl's
// (and ddtrace's, and the profiler's), and must chain to it: pcntl dispatches
// async signal handlers from there.
//  - idle: three signals, each followed by a VM safe point, with no error
//    pending: the handler runs three times.
//  - pending: the signal is queued (async signals off), then an allocation
//    crosses memory_limit=16M (usage about 22 MiB, below the 32 MiB hard
//    limit) and the queued signal and the pending error meet at the same
//    interrupt. The inner handler runs first, so the signal handler runs
//    (over the soft limit, before any error) and only then is the deferred
//    fatal raised.
require __DIR__ . '/inc/php2shell_cgi.php';
const CHILD = __DIR__ . '/inc/php2shell_child_interrupt_chain.php';
$soft = 16 * P2S_MIB;
$common = ['P2S_SOFT' => (string)$soft];

$r = p2s_cgi_run(['memory_limit' => '16M'], [], p2s_cgi_env(CHILD, 'GET', $common + ['P2S_CASE' => 'idle']));
echo "idle: ", preg_match('/^IDLE (.*)$/m', $r['out'], $m) ? $m[1] : 'no IDLE line',
    " handler_lines=", substr_count($r['out'], 'HANDLER signo='), "\n";
if (!$m) {
    p2s_dump($r);
}

$r = p2s_cgi_run(['memory_limit' => '16M'], [], p2s_cgi_env(CHILD, 'GET', $common + [
    'P2S_CASE' => 'pending',
    'P2S_ALLOC' => (string)(20 * P2S_MIB),
]));
$outcome = p2s_outcome($r, $soft);
$handler = strpos($r['out'], 'HANDLER signo=');
$fatal = strpos($r['out'] . $r['err'], 'Allowed memory size');
echo "pending: queued=", var_export(strpos($r['out'], 'QUEUED handled=0') !== false, true),
    " handler_ran=", var_export($handler !== false, true),
    " handler_over_soft=", var_export(strpos($r['out'], 'over_soft=true') !== false, true),
    " handler_before_fatal=", var_export($handler !== false && $fatal !== false && $handler < $fatal, true),
    " outcome=$outcome\n";
if ($outcome !== 'deferred' || $handler === false) {
    p2s_dump($r);
}
--EXPECT--
idle: handled=3 handler_lines=3
pending: queued=true handler_ran=true handler_over_soft=true handler_before_fatal=true outcome=deferred
