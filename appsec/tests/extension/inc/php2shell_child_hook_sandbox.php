<?php
// Child script for php2shell_mitigation_hook_sandbox.phpt: a tracer hook's
// begin closure, which the tracer runs in its sandbox, allocates P2S_ALLOC
// bytes, crossing memory_limit, then reaches VM safe points and prints
// CLOSURE DONE. SCRIPT RAN is printed only if no error was raised after the
// hooked call returned.
function p2s_safe_point() {}

// An internal function: a user function declared in this file is not hooked
// on every version.
DDTrace\install_hook('ltrim', static function (DDTrace\HookData $hook) {
    $s = str_repeat('x', (int)getenv('P2S_ALLOC'));
    p2s_safe_point();
    for ($i = 0; $i < 2; $i++) {}
    echo "CLOSURE DONE len=", strlen($s), "\n";
});

ltrim(' x');
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
echo "SCRIPT RAN\n";
