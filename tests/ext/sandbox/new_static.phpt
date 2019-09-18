--TEST--
New static instantiates from expected class
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

abstract class Foo {
    public static function get() {
        return new static();
    }
}

class Bar extends Foo {
    // Empty
}

dd_trace_method('Foo', 'get', function (SpanData $span) {
    $span->name = get_called_class();
});

$bar = Bar::get();
var_dump($bar instanceof Bar);

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(true)
Bar
