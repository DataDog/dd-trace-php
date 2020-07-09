--TEST--
Check if for case insensitive class name support
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
--FILE--
<?php
class Base {
    public function method(){
        echo __METHOD__ . PHP_EOL;
    }
}

dd_trace("base", "method", function () {
    echo "HOOK ";
    return dd_trace_forward_call();
});

(new Base())->method();
(new base())->method();

?>
--EXPECT--
HOOK Base::method
HOOK Base::method
