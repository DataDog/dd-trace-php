--TEST--
Set the trace ID from userland
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php
use DDTrace\SpanData;

dd_trace_function('array_sum', function (SpanData $span) {
    $span->name = 'array_sum';
});

var_dump(dd_trace_set_trace_id('42'));
echo dd_trace_push_span_id('100') . PHP_EOL;

var_dump(array_sum([1, 1]));

$printSpans = function($span) {
    printf(
        "Name: %s\nSpan ID: %s\nTrace ID: %s\nParent ID: %s\n",
        $span['name'],
        $span['span_id'],
        $span['trace_id'],
        isset($span['parent_id']) ? $span['parent_id'] : '(empty)'
    );
};
array_map($printSpans, dd_trace_serialize_closed_spans());

echo '*Flushed spans*' . PHP_EOL;
var_dump(array_sum([1, 1]));

array_map($printSpans, dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(true)
100
int(2)
Name: array_sum
Span ID: 6965080426129060204
Trace ID: 42
Parent ID: 100
*Flushed spans*
int(2)
Name: array_sum
Span ID: 5894024288751747413
Trace ID: 5894024288751747413
Parent ID: (empty)
