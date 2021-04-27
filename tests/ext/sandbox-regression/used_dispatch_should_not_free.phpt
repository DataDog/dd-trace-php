--TEST--
[Sandbox regression] Override traced function from within itself
--SKIPIF--
<?php if (PHP_VERSION_ID >= 80000) die('skip: Dispatch cannot be overwritten on PHP 8+'); ?>
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
OLD HOOK METHOD exec_a
NEW HOOK METHOD exec_b
