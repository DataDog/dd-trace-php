--TEST--
Check sample rate is in effect
--ENV--
DD_SAMPLING_RATE=0
DD_SPAN_SAMPLING_RULES=[{"sample_rate":0.5,"max_per_second":10}]
DD_TRACE_DEBUG_PRNG_SEED=420
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\start_span();
DDTrace\close_span();

$last = dd_trace_serialize_closed_spans()[0]["metrics"];
print "First span: rule_rate={$last["_dd.span_sampling.rule_rate"]}\n";

$droppedCount = 0;
for ($i = 0; $i < 26; ++$i) {
    DDTrace\start_span();
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
    $droppedCount += !isset($last["_dd.span_sampling.mechanism"]);
}
echo "$droppedCount dropped out of 27\n";

$droppedCount = 0;
for ($i = 0; $i < 10; ++$i) {
    DDTrace\start_span();
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
    $droppedCount += !isset($last["_dd.span_sampling.mechanism"]);
}
echo "$droppedCount dropped out of 10\n";

usleep(100000);

DDTrace\start_span();
DDTrace\close_span();

$last = dd_trace_serialize_closed_spans()[0]["metrics"];
echo "22st span: rule_rate={$last["_dd.span_sampling.rule_rate"]}\n";

?>

--EXPECT--
First span: rule_rate=0.5
15 dropped out of 27
10 dropped out of 10
22st span: rule_rate=0.5
