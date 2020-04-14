--TEST--
Spans are automatically flushed when auto-flushing enabled
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: PHP 5.4 not supported'); ?>
<?php if (PHP_VERSION_ID < 70000) die('skip: Auto flushing not supported on PHP 5'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=1
--FILE--
<?php
use DDTrace\SpanData;

require 'fake_tracer.inc';

dd_trace_function('array_sum', function (SpanData $span, $args, $retval) {
    $span->name = 'array_sum';
    $span->resource = $retval;
});

dd_trace_function('main', function (SpanData $span) {
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
--EXPECT--
3
6
Flushing tracer...
main
array_sum (6)
array_sum (3)
Tracer reset

10
15
Flushing tracer...
main
array_sum (15)
array_sum (10)
Tracer reset

21
28
Flushing tracer...
main
array_sum (28)
array_sum (21)
Tracer reset
