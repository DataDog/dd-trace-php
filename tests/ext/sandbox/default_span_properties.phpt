--TEST--
Span properties defaults to values if not explicitly set (functions)
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
--EXPECT--
21
28
spans(\DDTrace\SpanData) (5) {
  main (default_span_properties.php, main, cli)
    max => 6
    _dd.p.upstream_services => ZGVmYXVsdF9zcGFuX3Byb3BlcnRpZXMucGhw|1|1|1.000
  array_sum (default_span_properties.php, array_sum, cli)
    retval => 28
  MyRange (default_span_properties.php, MyRange, cli)
  array_sum (default_span_properties.php, array_sum, cli)
    retval => 21
  MyRange (default_span_properties.php, MyRange, cli)
}
