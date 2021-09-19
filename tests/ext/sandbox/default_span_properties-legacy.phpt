--TEST--
Span properties defaults to values if not explicitly set (functions)
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die('skip: Test does not work with internal spans'); ?>
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum,range
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use DDTrace\SpanData;

DDTrace\trace_function('array_sum', function (SpanData $span, array $args, $retval) {
    $span->meta = ['retval' => $retval];
});

DDTrace\trace_function('range', function (SpanData $span) {
    $span->name = 'MyRange';
});

DDTrace\trace_function('main', function (SpanData $span, array $args) {
    $span->meta = ['max' => $args[0]];
});

function main($max) {
    echo array_sum(range(0, $max)) . PHP_EOL;
    echo array_sum(range(0, $max + 1)) . PHP_EOL;
}

main(6);

include 'dd_dumper.inc';
dd_dump_spans();
?>
--EXPECTF--
21
28
spans(\DDTrace\SpanData) (5) {
  main (main)
    max => 6
    system.pid => %d
  array_sum (array_sum)
    retval => 28
  MyRange (MyRange)
  array_sum (array_sum)
    retval => 21
  MyRange (MyRange)
}
