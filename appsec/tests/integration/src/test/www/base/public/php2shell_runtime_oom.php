<?php
// Runtime driver for Php2ShellMitigationTests' e_waf_activation: one
// allocation of ?alloc= bytes after request startup, then a VM safe point (a
// user function call and a loop: PHP 7.1-8.3 do not check for an interrupt
// after an internal call), where an armed mitigation raises its deferred
// fatal. Unarmed, the engine raises its own fatal inside the allocation. RAN
// is printed only if no error was raised by then.
function p2s_safe_point() {}
$s = str_repeat('x', (int)(isset($_GET['alloc']) ? $_GET['alloc'] : 1000));
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
echo "RAN len=", strlen($s), "\n";
