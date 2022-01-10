--TEST--
Set the trace ID from userland
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use DDTrace\SpanData;

DDTrace\trace_function('array_sum', function (SpanData $span) {
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
Span ID: 13930160852258120406
Trace ID: 42
Parent ID: 100
*Flushed spans*
int(2)
Name: array_sum
Span ID: 13874630024467741450
Trace ID: 13874630024467741450
Parent ID: (empty)
