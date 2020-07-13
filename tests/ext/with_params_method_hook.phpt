--TEST--
Check method with params can be overwritten and we're able to call original method with modified params
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
--FILE--
<?php
class Test {
    public function m($a, $b, $c){
        echo "METHOD " . $a ." ". $b . " " . $c . PHP_EOL;
    }
}

dd_trace("Test", "m", function($a, $b, $c){
    $this->m($a, $b, $a . $b . $c);
    echo "HOOK " . $a ." ". $b . " " . $c . PHP_EOL;
});

(new Test())->m("a", "b", "c");

?>
--EXPECT--
METHOD a b abc
HOOK a b c
