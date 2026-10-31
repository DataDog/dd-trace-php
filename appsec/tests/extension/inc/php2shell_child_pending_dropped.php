<?php
// Child script for php2shell_mitigation_runtime_pending_dropped.phpt, run by
// php-cgi -T 2: every request does the same.
//  1. A VM safe point (a user function call and a loop), then a 1 MiB
//     allocation and another safe point: an error pending from an earlier
//     request would be raised here.
//  2. REQ RAN is printed.
//  3. The last statement concatenates a P2S_HALF-byte string with itself.
//     ZEND_CONCAT allocates the result inside the VM, and nothing after it
//     checks for an interrupt: not the assignment, nor the return from the
//     main script (no shutdown function, destructor or user output handler
//     follows). A crossing of memory_limit there stays pending until the
//     request is disarmed.
function p2s_safe_point() {}
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
$small = str_repeat('s', 1048576);
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
echo "REQ RAN usage=", memory_get_usage(true), "\n";
$a = str_repeat('x', (int)getenv('P2S_HALF'));
$b = $a . $a;
