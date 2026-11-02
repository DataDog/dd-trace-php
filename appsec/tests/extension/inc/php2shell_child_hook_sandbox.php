<?php
// Child script for php2shell_mitigation_hook_sandbox*.phpt: a tracer hook's
// begin closure, which the tracer runs in its sandbox, allocates P2S_ALLOC
// bytes, crossing memory_limit, then reaches VM safe points and prints
// CLOSURE DONE. With P2S_KEEP the allocation outlives the closure, so usage
// stays above memory_limit after it. With P2S_ML the closure then sets
// memory_limit to it, before the error is raised. SCRIPT RAN is printed only if no error
// was raised after the hooked call returned.
function p2s_safe_point() {}

// An internal function: a user function declared in this file is not hooked
// on every version.
DDTrace\install_hook('ltrim', static function (DDTrace\HookData $hook) {
    $s = str_repeat('x', (int)getenv('P2S_ALLOC'));
    if (getenv('P2S_KEEP')) {
        $GLOBALS['p2s_keep'] = $s;
    }
    p2s_safe_point();
    for ($i = 0; $i < 2; $i++) {}
    if (getenv('P2S_ML')) {
        ini_set('memory_limit', getenv('P2S_ML'));
    }
    echo "CLOSURE DONE len=", strlen($s), "\n";
});

ltrim(' x');
$after = str_repeat('y', 64);
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
echo "SCRIPT RAN\n";
