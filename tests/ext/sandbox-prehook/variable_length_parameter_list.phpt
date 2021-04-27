--TEST--
[Prehook regression] Trace variadic functions and methods
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--FILE--
<?php
function test($a, $b, $c){
    echo "FUNCTION " . $a ." ". $b . " " . $c . " " . implode(" ", array_slice(func_get_args(), 3)) .  PHP_EOL;
}

class Test {
    public function m($a, $b, $c){
        echo "METHOD " . $a ." ". $b . " " . $c . " " . implode(" ", array_slice(func_get_args(), 3)) .  PHP_EOL;
    }
}

DDTrace\trace_function("test", ['prehook' => function($s, array $args){
    echo "HOOK " . implode(" ", $args) . PHP_EOL;
}]);

DDTrace\trace_method("Test", "m", ['prehook' => function($s, array $args){
    echo "HOOK " . implode(" ", $args) . PHP_EOL;
}]);


test("a", "b", "c", "d", "e", "f", "g", "h");
test("a1", "b", "c", "d", "e", "f", "g", "h");
test("a2", "b", "c", "d", "e", "f", "g", "h");
test("a3", "b", "c", "d", "e", "f", "g", "h");
(new Test())->m("a", "b", "c", "d", "e", "f", "g", "h");
(new Test())->m("a1", "b", "c", "d", "e", "f", "g", "h");
(new Test())->m("a2", "b", "c", "d", "e", "f", "g", "h");
(new Test())->m("a3", "b", "c", "d", "e", "f", "g", "h");

?>
--EXPECT--
HOOK a b c d e f g h
FUNCTION a b c d e f g h
HOOK a1 b c d e f g h
FUNCTION a1 b c d e f g h
HOOK a2 b c d e f g h
FUNCTION a2 b c d e f g h
HOOK a3 b c d e f g h
FUNCTION a3 b c d e f g h
HOOK a b c d e f g h
METHOD a b c d e f g h
HOOK a1 b c d e f g h
METHOD a1 b c d e f g h
HOOK a2 b c d e f g h
METHOD a2 b c d e f g h
HOOK a3 b c d e f g h
METHOD a3 b c d e f g h
