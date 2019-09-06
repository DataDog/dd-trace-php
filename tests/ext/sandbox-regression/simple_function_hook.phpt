--TEST--
[Sandbox regression] Userland function is traced
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
function test(){
    return "FUNCTION";
}

dd_trace_function("test", function($s, $a, $retval){
    echo $retval . ' HOOK' . PHP_EOL;
});

test();

?>
--EXPECT--
FUNCTION HOOK
