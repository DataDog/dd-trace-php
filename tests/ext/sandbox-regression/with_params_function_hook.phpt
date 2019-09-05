--TEST--
[Sandbox regression] Trace function with params
--DESCRIPTION--
This differs from the original dd_trace() test in that it does not modify the original call arguments
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
function test($a, $b, $c){
    echo "FUNCTION " . $a ." ". $b . " " . $c . PHP_EOL;
}

dd_trace_function("test", function($s, array $args){
    list($a, $b, $c) = $args;
    echo "HOOK " . $a ." ". $b . " " . $c . PHP_EOL;
});

test("a", "b", "c");

?>
--EXPECT--
FUNCTION a b c
HOOK a b c
