--TEST--
New static instantiates from expected class
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
--FILE--
<?php
abstract class Foo {
    public static function get() {
        return new static();
    }
}

class Bar extends Foo {
    // Empty
}

dd_trace('Foo', 'get', function () {
    return dd_trace_forward_call();
});

$bar = Bar::get();
var_dump($bar instanceof Bar);
?>
--EXPECT--
bool(true)
