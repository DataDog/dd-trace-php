--TEST--
Check function with variable list of  params can be overwritten and we're able to call original function with modified params
--FILE--
<?php
function test($a, $b, $c, ...$args){
    echo "FUNCTION " . $a ." ". $b . " " . $c . " " . implode(" ", $args) .  PHP_EOL;
}

class Test {
    public function m($a, $b, $c, ...$args){
        echo "METHOD " . $a ." ". $b . " " . $c . " " . implode(" ", $args) .  PHP_EOL;
    }
}

dd_trace("test", function($a, ...$args){
    // $numargs = func_num_args();
    // join(" ", $args);
    test(...$args);
    echo "HOOK " . $a ." ". join(" ", $args) . PHP_EOL;
});

dd_trace("Test", "m", function($a, ...$args){
    $this->m(...$args);
    echo "HOOK " . $a ." ". join(" ", $args) . PHP_EOL;
});


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
FUNCTION b c d e f g h
HOOK a b c d e f g h
FUNCTION b c d e f g h
HOOK a1 b c d e f g h
FUNCTION b c d e f g h
HOOK a2 b c d e f g h
FUNCTION b c d e f g h
HOOK a3 b c d e f g h
METHOD b c d e f g h
HOOK a b c d e f g h
METHOD b c d e f g h
HOOK a1 b c d e f g h
METHOD b c d e f g h
HOOK a2 b c d e f g h
METHOD b c d e f g h
HOOK a3 b c d e f g h
