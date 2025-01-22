--TEST--
Check sample rate is in effect
--SKIPIF--
<?php if (getenv("USE_ZEND_ALLOC") === "0" && !getenv("SKIP_ASAN")) die('skip timing sensitive test, does not make sense with valgrind'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_SAMPLE_RATE=0
DD_SPAN_SAMPLING_RULES=[{"sample_rate":0.5,"max_per_second":3}]
DD_TRACE_DEBUG_PRNG_SEED=23
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\start_span();
DDTrace\close_span();

$last = dd_trace_serialize_closed_spans()[0]["metrics"];
print "First span: rule_rate={$last["_dd.span_sampling.rule_rate"]}\n";

$droppedCount = 0;
for ($i = 0; $i < 7; ++$i) {
    DDTrace\start_span();
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
    $droppedCount += !isset($last["_dd.span_sampling.mechanism"]);
}
echo "$droppedCount dropped out of 7\n";

$droppedCount = 0;
for ($i = 0; $i < 3; ++$i) {
    DDTrace\start_span();
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
    $droppedCount += !isset($last["_dd.span_sampling.mechanism"]);
}
echo "$droppedCount dropped out of 3\n";

usleep(350000);

DDTrace\start_span();
DDTrace\close_span();

$last = dd_trace_serialize_closed_spans()[0]["metrics"];
echo "11th span: rule_rate={$last["_dd.span_sampling.rule_rate"]}\n";

?>

--EXPECT--
First span: rule_rate=0.5
3 dropped out of 7
3 dropped out of 3
11th span: rule_rate=0.5
