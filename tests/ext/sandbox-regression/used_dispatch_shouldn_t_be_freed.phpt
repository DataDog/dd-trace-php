--TEST--
[Sandbox regression] Override traced function from within itself
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
