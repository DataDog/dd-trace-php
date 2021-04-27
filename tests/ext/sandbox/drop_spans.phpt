--TEST--
Span is dropped when tracing closure returns false
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum,DateTime::__construct
--FILE--
<?php
use DDTrace\SpanData;

date_default_timezone_set('UTC');

DDTrace\trace_function('array_sum', function (SpanData $span, array $args, $retval) {
    echo 'Traced array_sum' . PHP_EOL;
    $span->name = 'ArraySum: ' . $retval;
    if ($args[0][0] === 42) {
        return false;
    }
});

DDTrace\trace_method('DateTime', '__construct', function (SpanData $span, array $args) {
    echo 'Traced DateTime' . PHP_EOL;
    $span->name = 'DateTime: ' . $args[0];
    if ($this->format('Y-m-d') === '2019-09-10') {
        return false;
    }
});

array_sum([1, 2, 3]);
array_sum([42, 0, 1]); // Should drop
new DateTime('2000-01-01');
new DateTime('2019-09-10'); // Should drop

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
Traced array_sum
Traced array_sum
Traced DateTime
Traced DateTime
DateTime: 2000-01-01
ArraySum: 6
