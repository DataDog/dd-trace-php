--TEST--
Push a span ID onto the stack from userland
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php
echo dd_trace_push_span_id('42') . PHP_EOL;
foreach (range(0, 2) as $i) {
    echo dd_trace_push_span_id() . PHP_EOL;
}
echo dd_trace_push_span_id('invalid') . PHP_EOL;
echo dd_trace_push_span_id('16142341506862590864') . PHP_EOL; // uint64
echo dd_trace_push_span_id('99999999999999999999999999999') . PHP_EOL; // overflow
echo dd_trace_push_span_id() . PHP_EOL;

echo "\n";

foreach (range(0, 7) as $i) {
    echo dd_trace_pop_span_id() . PHP_EOL;
}
echo dd_trace_pop_span_id() . PHP_EOL;
?>
--EXPECT--
42
6965080426129060204
5894024288751747413
6937315012233870726
1256893659602577832
16142341506862590864
8331185726714219691
867627036267489215

867627036267489215
8331185726714219691
16142341506862590864
1256893659602577832
6937315012233870726
5894024288751747413
6965080426129060204
42
0
