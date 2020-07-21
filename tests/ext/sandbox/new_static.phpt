--TEST--
New static instantiates from expected class
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

abstract class Foo
{
    public static function get()
    {
        return new static();
    }
}

class Bar extends Foo
{
    // Empty
}

DDTrace\trace_method('Foo', 'get', function (SpanData $span) {
    $span->name = $span->resource = get_called_class();
    $span->service = 'phpt';
});

$bar = Bar::get();
var_dump($bar instanceof Bar);

foreach (dd_trace_serialize_closed_spans() as $span) {
        echo $span['name'] . PHP_EOL;
}

?>
--EXPECT--
bool(true)
Bar
