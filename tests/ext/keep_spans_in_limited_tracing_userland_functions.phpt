--TEST--
[Legacy] Keep spans in limited mode (userland functions)
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--ENV--
DD_TRACE_SPANS_LIMIT=5
--FILE--
<?php
function myFunc1($foo) {
    return $foo;
}

function myFunc2($bar) {
    // ...
}

dd_trace('myFunc1', function () {
    dd_trace_push_span_id();
    echo 'myFunc1' . PHP_EOL;
    dd_trace_pop_span_id();
    return dd_trace_forward_call();
});
dd_trace('myFunc2', [
    'instrument_when_limited' => 1,
    'innerhook' => function () {
        dd_trace_push_span_id();
        echo 'myFunc2' . PHP_EOL;
        dd_trace_pop_span_id();
        return dd_trace_forward_call();
    }
]);

var_dump(dd_trace_tracer_is_limited());
myFunc2('foo');
for ($i = 0; $i < 100; $i++) {
    $retval = myFunc1([]);
}
var_dump(dd_trace_tracer_is_limited());
myFunc2(42);
myFunc2(true);

// No internal spans should have been created
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(false)
myFunc2
myFunc1
myFunc1
myFunc1
myFunc1
bool(true)
myFunc2
myFunc2
array(0) {
}
