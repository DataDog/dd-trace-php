--TEST--
[Legacy] Keep spans in limited mode (internal functions)
--SKIPIF--
<?php if (PHP_MAJOR_VERSION > 5) die('skip: test requires legacy API'); ?>
--ENV--
DD_TRACE_SPANS_LIMIT=5
--FILE--
<?php
dd_trace('array_sum', function () {
    dd_trace_push_span_id();
    echo 'array_sum' . PHP_EOL;
    dd_trace_pop_span_id();
    return dd_trace_forward_call();
});
dd_trace('mt_rand', [
    'instrument_when_limited' => 1,
    'innerhook' => function () {
        dd_trace_push_span_id();
        echo 'mt_rand' . PHP_EOL;
        dd_trace_pop_span_id();
        return dd_trace_forward_call();
    }
]);

var_dump(dd_trace_tracer_is_limited());
mt_rand();
for ($i = 0; $i < 100; $i++) {
    array_sum([]);
}
var_dump(dd_trace_tracer_is_limited());
mt_rand();
mt_rand();

// No internal spans should have been created
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(false)
mt_rand
array_sum
array_sum
array_sum
array_sum
bool(true)
mt_rand
mt_rand
array(0) {
}
