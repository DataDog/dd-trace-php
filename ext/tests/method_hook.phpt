--TEST--
Check method can be overwritten and we're able to call original method
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
