<?php
// Child script for php2shell_mitigation_interrupt_reentry.phpt, run with
// p2s_stub.interrupt=1: the stub's zend_interrupt_function, under the
// mitigation's, calls p2s_cb(), which prints
//   CB depth=N
// (N: how many calls of p2s_cb() are active) and keeps 2 MiB more. Then it
// sets EG(vm_interrupt) through the stub and reaches a VM safe point, so the
// mitigation's interrupt function is entered again from inside the call, as
// a timer or signal firing during an Excimer or pcntl callback does.
// P2S_CASE:
//   idle     no error pending: it does so while N < 3. Prints IDLE
//            max_depth=N.
//   pending  while an error is pending (at most 64 times). An allocation of
//            P2S_ALLOC bytes crosses memory_limit and requests the
//            interrupt. SCRIPT RAN is printed only if no error was raised.
function p2s_safe_point() {}
function p2s_cb() {
    global $depth, $max_depth, $keep;
    $depth++;
    $max_depth = max($max_depth, $depth);
    echo "CB depth=$depth\n";
    $keep[] = str_repeat('y', 2 * 1048576);
    $st = \datadog\appsec\testing\php2shell_mitigation_request_status();
    if (getenv('P2S_CASE') === 'idle' ? $depth < 3 : $st['pending'] && $depth < 64) {
        p2s_stub_vm_interrupt();
        p2s_safe_point();
        for ($i = 0; $i < 2; $i++) {}
    }
    $depth--;
}
$depth = 0;
$max_depth = 0;
$keep = [];
p2s_stub_interrupt_callback('p2s_cb');

if (getenv('P2S_CASE') === 'idle') {
    p2s_stub_vm_interrupt();
    p2s_safe_point();
    for ($i = 0; $i < 2; $i++) {}
    echo "IDLE max_depth=$max_depth\n";
} else {
    $s = str_repeat('x', (int)getenv('P2S_ALLOC'));
    p2s_safe_point();
    for ($i = 0; $i < 2; $i++) {}
    echo "SCRIPT RAN len=", strlen($s), "\n";
}
