--TEST--
[Legacy] Keep spans in limited mode (userland methods)
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--ENV--
DD_TRACE_SPANS_LIMIT=5
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

dd_trace('MyClass', 'myMethod1', function () {
    dd_trace_push_span_id();
    echo 'MyClass.myMethod1' . PHP_EOL;
    dd_trace_pop_span_id();
    return dd_trace_forward_call();
});
dd_trace('MyClass', 'myMethod2', [
    'instrument_when_limited' => 1,
    'innerhook' => function () {
        dd_trace_push_span_id();
        echo 'MyClass.myMethod2' . PHP_EOL;
        dd_trace_pop_span_id();
        return dd_trace_forward_call();
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

// No internal spans should have been created
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(false)
MyClass.myMethod2
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
bool(true)
MyClass.myMethod2
MyClass.myMethod2
array(0) {
}
