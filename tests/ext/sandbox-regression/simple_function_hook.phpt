--TEST--
[Sandbox regression] Check user defined function can be overriden and we're able to call the original
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
function test(){
    return "FUNCTION";
}

dd_trace("test", function(){
    return test() . ' HOOK' . PHP_EOL;
});

echo test();

?>
--EXPECT--
FUNCTION HOOK
