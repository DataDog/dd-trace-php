--TEST--
Check if for case insensitive class name support
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
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
