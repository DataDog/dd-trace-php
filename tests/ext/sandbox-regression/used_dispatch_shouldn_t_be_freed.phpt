--TEST--
[Sandbox regression] Override traced function from within itself
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
function test($a){
    dd_trace_function("test", function($s, $a, $retval){
        echo 'NEW HOOK ' . $retval . PHP_EOL;
    });
    return 'METHOD ' . $a;
}

dd_trace_function("test", function($s, $a, $retval){
    echo 'OLD HOOK ' . $retval . PHP_EOL;
});

test("exec_a");
test("exec_b");

?>
--EXPECT--
OLD HOOK METHOD exec_a
NEW HOOK METHOD exec_b
