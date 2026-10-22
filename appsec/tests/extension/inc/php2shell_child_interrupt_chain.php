<?php
// Child script for php2shell_mitigation_interrupt_chain.phpt: a pcntl
// SIGUSR1 handler, dispatched from pcntl's zend_interrupt_function, which the
// mitigation's own interrupt function chains to. The handler prints
//   HANDLER signo=N over_soft=true|false
// over_soft: memory_get_usage(true) is above P2S_SOFT bytes, i.e. the handler
// runs after a crossing of memory_limit.
// P2S_CASE:
//   idle     async signals on; three times: posix_kill() to self, then a VM
//            safe point (a user function call and a loop). Prints IDLE
//            handled=N.
//   pending  async signals off while the signal is sent, so pcntl only
//            queues it and does not request an interrupt; prints QUEUED
//            handled=N. Then async signals on, and an allocation of
//            P2S_ALLOC bytes that crosses memory_limit: the mitigation makes
//            the error pending and requests the interrupt, at which both the
//            queued signal and the pending error are delivered. SCRIPT RAN is
//            printed only if no error was raised by the safe point.
function p2s_safe_point() {}
$handled = 0;
pcntl_signal(SIGUSR1, function ($signo) use (&$handled) {
    $handled++;
    echo "HANDLER signo=$signo over_soft=",
        var_export(memory_get_usage(true) > (int)getenv('P2S_SOFT'), true), "\n";
});

if (getenv('P2S_CASE') === 'idle') {
    pcntl_async_signals(true);
    for ($n = 0; $n < 3; $n++) {
        posix_kill(getmypid(), SIGUSR1);
        p2s_safe_point();
        for ($i = 0; $i < 2; $i++) {}
    }
    echo "IDLE handled=$handled\n";
} else {
    pcntl_async_signals(false);
    posix_kill(getmypid(), SIGUSR1);
    p2s_safe_point();
    for ($i = 0; $i < 2; $i++) {}
    echo "QUEUED handled=$handled\n";
    pcntl_async_signals(true);
    $s = str_repeat('x', (int)getenv('P2S_ALLOC'));
    p2s_safe_point();
    for ($i = 0; $i < 2; $i++) {}
    echo "SCRIPT RAN handled=$handled len=", strlen($s), "\n";
}
