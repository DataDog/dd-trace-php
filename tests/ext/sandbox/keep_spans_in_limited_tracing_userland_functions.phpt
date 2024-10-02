--TEST--
Keep spans in limited mode (userland functions)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_SPANS_LIMIT=5
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
function myFunc1($foo) {
    return $foo;
}

function myFunc2($bar) {
    return $bar;
}

DDTrace\trace_function('myFunc1', function (\DDTrace\SpanData $span) {
    $span->name = 'myFunc1';
});
DDTrace\trace_function('myFunc2', [
    'instrument_when_limited' => 1,
    'posthook' => function (\DDTrace\SpanData $span) {
        $span->name = 'myFunc2';
    }
]);

var_dump(dd_trace_tracer_is_limited());
myFunc2('foo');
for ($i = 0; $i < 100; $i++) {
    myFunc1([]);
}
var_dump(dd_trace_tracer_is_limited());
myFunc2(42);
myFunc2(true);

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(false)
bool(true)
myFunc2
myFunc2
myFunc1
myFunc1
myFunc1
myFunc1
myFunc2
