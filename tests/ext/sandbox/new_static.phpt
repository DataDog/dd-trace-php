--TEST--
New static instantiates from expected class
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
use DDTrace\SpanData;

class Foo
{
    public static function get()
    {
        return new static();
    }
}

class Bar extends Foo
{
    public static function get2()
    {
        // scope of this call is Foo
        return Foo::get();
    }

    public static function get3()
    {
        /* scope of this call is Bar; see:
         * https://wiki.php.net/rfc/lsb_parentself_forwarding
         */
        return parent::get();
    }
}

DDTrace\trace_method('Foo', 'get', function (SpanData $span) {
    $scope = get_called_class();
    echo "Called with scope {$scope}.\n";

    // intentionally use default span properties to test LSB scopes
    $span->service = 'phpt';
});

$bar = Bar::get();
var_dump($bar instanceof Bar);

function dump_span_names($spans) {
    foreach ($spans as $span) {
        echo $span['name'], "\n";
    }
}

dump_span_names(dd_trace_serialize_closed_spans());

$bar = Bar::get2();
var_dump($bar instanceof Foo);

dump_span_names(dd_trace_serialize_closed_spans());

$bar = Bar::get3();
var_dump($bar instanceof Bar);

dump_span_names(dd_trace_serialize_closed_spans());

?>
--EXPECT--
Called with scope Bar.
bool(true)
Bar.get
Called with scope Foo.
bool(true)
Foo.get
Called with scope Bar.
bool(true)
Bar.get
