--TEST--
[Sandbox regression] Check method with params can be overwritten and we're able to call original method with modified params
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
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
