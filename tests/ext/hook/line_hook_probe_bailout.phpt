--TEST--
The first install_line_hook() resolves the handler ABI and self-tests; outer observers must survive that
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function inner() {
    $a = 1;
    return $a;
}

function outer() {
    // First call in the process: this resolves the handler ABI and runs the self-test, which compiles a throwaway function, arms an opline in it and calls it -- all while outer() is on the stack.
    // An earlier implementation probed the ABI by deliberately zend_bailout()ing out of a scratch frame, which left the abandoned frame in EG(current_observed_frame) and made zend_observer_fcall_end() no-op for every frame already on the stack, so outer()'s end observer never ran again.
    // Asking the engine for the ABI removed the bailout; this pins the invariant that whatever the first install does, it leaves observers on the existing stack intact.
    DDTrace\install_line_hook(__FILE__, 4, function () {});
    inner();
    return 'ok';
}

$log = [];
DDTrace\install_hook(
    'outer',
    function () use (&$log) { $log[] = 'outer:begin'; },
    function () use (&$log) { $log[] = 'outer:end'; }
);
DDTrace\install_hook(
    'inner',
    function () use (&$log) { $log[] = 'inner:begin'; },
    function () use (&$log) { $log[] = 'inner:end'; }
);

var_dump(outer());
echo implode(',', $log), "\n";
echo "Done.\n";
?>
--EXPECT--
string(2) "ok"
outer:begin,inner:begin,inner:end,outer:end
Done.
