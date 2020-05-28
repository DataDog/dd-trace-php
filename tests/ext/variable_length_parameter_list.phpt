--TEST--
Check function with variable list of  params can be overwritten and we're able to call original function with modified params
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
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

dd_trace("test", function($a){
    $args = array_slice(func_get_args(), 1);
    call_user_func_array('test', $args);
    echo "HOOK " . $a ." ". join(" ", $args) . PHP_EOL;
});

dd_trace("Test", "m", function($a){
    $args = array_slice(func_get_args(), 1);
    call_user_func_array(array($this, 'm'), $args);
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
