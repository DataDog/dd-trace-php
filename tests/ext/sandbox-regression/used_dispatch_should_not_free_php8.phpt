--TEST--
[Sandbox regression] Override traced function from within itself
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: Dispatch can be overwritten on PHP < 8'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
function test($a){
    DDTrace\trace_function("test", function($s, $a, $retval){
        echo 'NEW HOOK ' . $retval . PHP_EOL;
    });
    return 'METHOD ' . $a;
}

DDTrace\trace_function("test", function($s, $a, $retval){
    echo 'OLD HOOK ' . $retval . PHP_EOL;
});

test("exec_a");
test("exec_b");
?>
--EXPECT--
Cannot overwrite existing dispatch for 'test()'
OLD HOOK METHOD exec_a
Cannot overwrite existing dispatch for 'test()'
OLD HOOK METHOD exec_b
Successfully triggered auto-flush with trace of size 3
