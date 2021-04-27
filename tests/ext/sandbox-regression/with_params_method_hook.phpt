--TEST--
[Sandbox regression] Trace method with params
--DESCRIPTION--
This differs from the original dd_trace() test in that it does not modify the original call arguments
--FILE--
<?php
class Test {
    public function m($a, $b, $c){
        echo "METHOD " . $a ." ". $b . " " . $c . PHP_EOL;
    }
}

DDTrace\trace_method("Test", "m", function($s, array $args){
    list($a, $b, $c) = $args;
    echo "HOOK " . $a ." ". $b . " " . $c . PHP_EOL;
});

(new Test())->m("a", "b", "c");

?>
--EXPECT--
METHOD a b c
HOOK a b c
