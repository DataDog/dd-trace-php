--TEST--
[Prehook Regression] New static instantiates from expected class
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

DDTrace\trace_method('Foo', 'get', ['prehook' => function (SpanData $span) {
    $span->name = get_called_class();
}]);

$bar = Bar::get();
var_dump($bar instanceof Bar);

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(true)
Bar
