--TEST--
Spans are automatically flushed when auto-flushing enabled
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
DD_TRACE_DEBUG=1
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use DDTrace\SpanData;

DDTrace\trace_function('array_sum', function (SpanData $span, $args, $retval) {
    $span->name = 'array_sum';
    $span->resource = $retval;
});

DDTrace\trace_function('main', function (SpanData $span) {
    $span->name = 'main';
});

function main($max) {
    echo array_sum(range(0, $max)) . PHP_EOL;
    echo array_sum(range(0, $max + 1)) . PHP_EOL;
}

main(2);
echo PHP_EOL;
main(4);
echo PHP_EOL;
main(6);
echo PHP_EOL;
?>
--EXPECTF--
3
6
Flushing trace of size 3 to send-queue for %s

10
15
Flushing trace of size 3 to send-queue for %s

21
28
Flushing trace of size 3 to send-queue for %s

No finished traces to be sent to the agent