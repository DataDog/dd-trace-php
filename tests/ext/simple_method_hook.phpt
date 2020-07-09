--TEST--
Check method can be overwritten and we're able to call original method
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
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
