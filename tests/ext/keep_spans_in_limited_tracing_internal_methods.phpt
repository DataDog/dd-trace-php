--TEST--
[Legacy] Keep spans in limited mode (internal methods)
--SKIPIF--
<?php if (PHP_MAJOR_VERSION > 5) die('skip: test requires legacy API'); ?>
--ENV--
DD_TRACE_SPANS_LIMIT=5
--FILE--
<?php
date_default_timezone_set('UTC');

dd_trace('DateTime', 'format', function () {
    dd_trace_push_span_id();
    echo 'DateTime.format' . PHP_EOL;
    dd_trace_pop_span_id();
    return dd_trace_forward_call();
});
dd_trace('DateTime', 'setTime', [
    'instrument_when_limited' => 1,
    'innerhook' => function () {
        dd_trace_push_span_id();
        echo 'DateTime.setTime' . PHP_EOL;
        dd_trace_pop_span_id();
        return dd_trace_forward_call();
    }
]);

var_dump(dd_trace_tracer_is_limited());
$dt = new DateTime('2019-12-20');
$dt->setTime(8, 10);
for ($i = 0; $i < 100; $i++) {
    $formatted = $dt->format('r');
}
var_dump(dd_trace_tracer_is_limited());
$dt->setTime(8, 11);
$dt->setTime(8, 12);

// No internal spans should have been created
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(false)
DateTime.setTime
DateTime.format
DateTime.format
DateTime.format
DateTime.format
bool(true)
DateTime.setTime
DateTime.setTime
array(0) {
}
