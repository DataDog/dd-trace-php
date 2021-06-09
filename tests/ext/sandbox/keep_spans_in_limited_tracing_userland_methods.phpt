--TEST--
Keep spans in limited mode (userland methods)
--ENV--
DD_TRACE_SPANS_LIMIT=5
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
class MyClass
{
    public function myMethod1($foo) {
        return $foo;
    }

    public function myMethod2($bar) {
        return $bar;
    }
}

DDTrace\trace_method('MyClass', 'myMethod1', function (\DDTrace\SpanData $span) {
    $span->name = 'MyClass.myMethod1';
});
DDTrace\trace_method('MyClass', 'myMethod2', [
    'instrument_when_limited' => 1,
    'posthook' => function (\DDTrace\SpanData $span) {
        $span->name = 'MyClass.myMethod2';
    }
]);

var_dump(dd_trace_tracer_is_limited());
$mc = new MyClass();
$mc->myMethod2('foo');
for ($i = 0; $i < 100; $i++) {
    $mc->myMethod1('bar');
}
var_dump(dd_trace_tracer_is_limited());
$mc->myMethod2(42);
$mc->myMethod2(true);

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(false)
bool(true)
MyClass.myMethod2
MyClass.myMethod2
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod2
