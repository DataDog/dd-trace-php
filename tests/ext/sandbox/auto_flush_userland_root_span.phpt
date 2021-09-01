--TEST--
Userland root spans are automatically flushed when auto-flushing enabled
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
DD_TRACE_DEBUG=1
--FILE--
<?php
use DDTrace\SpanData;

DDTrace\trace_function('array_sum', function (SpanData $span, $args, $retval) {
    $span->name = 'array_sum';
    $span->resource = $retval;
});

function main($max) {
    // Emulate opening a userland span
    dd_trace_push_span_id();
    echo array_sum(range(0, $max)) . PHP_EOL;
    echo array_sum(range(0, $max + 1)) . PHP_EOL;
    echo 'Has not flushed yet.' . PHP_EOL;
    // Emulate closing a userland span
    dd_trace_pop_span_id();
}

main(2);
echo PHP_EOL;
main(4);
echo PHP_EOL;
main(6);
echo PHP_EOL;
?>
--EXPECT--
3
6
Has not flushed yet.
Successfully triggered flush with trace of size 2

10
15
Has not flushed yet.
Successfully triggered flush with trace of size 2

21
28
Has not flushed yet.
Successfully triggered flush with trace of size 2

No finished traces to be sent to the agent