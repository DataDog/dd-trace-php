--TEST--
Check user defined function can be overriden and we're able to call the original
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
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
