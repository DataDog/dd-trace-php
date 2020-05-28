--TEST--
Check function with params can be overwritten and we're able to call original function with modified params
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
function test($a, $b, $c){
    echo "FUNCTION " . $a ." ". $b . " " . $c . PHP_EOL;
}

dd_trace("test", function($a, $b, $c){
    test($a, $b, $a . $b . $c);
    echo "HOOK " . $a ." ". $b . " " . $c . PHP_EOL;
});

test("a", "b", "c");

?>
--EXPECT--
FUNCTION a b abc
HOOK a b c
