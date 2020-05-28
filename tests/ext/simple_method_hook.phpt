--TEST--
Check method can be overwritten and we're able to call original method
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
class Test {
    public function m(){
        echo "METHOD" . PHP_EOL;
    }
}

dd_trace("Test", "m", function(){
    $this->m();
    echo "HOOK" . PHP_EOL;
});

(new Test())->m();

?>
--EXPECT--
METHOD
HOOK
