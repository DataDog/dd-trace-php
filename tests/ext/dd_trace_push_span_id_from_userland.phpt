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
13930160852258120406
11788048577503494824
13874630024467741450
2513787319205155662
16142341506862590864
16662371453428439381
1735254072534978428

1735254072534978428
16662371453428439381
16142341506862590864
2513787319205155662
13874630024467741450
11788048577503494824
13930160852258120406
42
0
