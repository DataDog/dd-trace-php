--TEST--
[Sandbox regression] Userland function is traced
--FILE--
<?php
function test(){
    return "FUNCTION";
}

DDTrace\trace_function("test", function($s, $a, $retval){
    echo $retval . ' HOOK' . PHP_EOL;
});

test();

?>
--EXPECT--
FUNCTION HOOK
