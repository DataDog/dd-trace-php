--TEST--
Test max_per_second single span limiting with multiple buckets
--ENV--
DD_SAMPLING_RATE=0
DD_SPAN_SAMPLING_RULES=[{"sample_rate":1,"max_per_second":2,"service":"a","name":"b"},{"sample_rate":1,"max_per_second":2,"service":"a"},{"sample_rate":1,"max_per_second":2,"name":"b"}]
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

$droppedCount = 0;
for ($i = 0; $i < 5; ++$i) {
    DDTrace\start_span();
    DDTrace\active_span()->service = "a";
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
    $droppedCount += !isset($last["_dd.span_sampling.mechanism"]);
}

echo "dropped_count for service only: $droppedCount\n";

$droppedCount = 0;
for ($i = 0; $i < 5; ++$i) {
    DDTrace\start_span();
    DDTrace\active_span()->name = "b";
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
    $droppedCount += !isset($last["_dd.span_sampling.mechanism"]);
}

echo "dropped_count for name only: $droppedCount\n";

$droppedCount = 0;
for ($i = 0; $i < 5; ++$i) {
    DDTrace\start_span();
    DDTrace\active_span()->service = "a";
    DDTrace\active_span()->name = "b";
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
    $droppedCount += !isset($last["_dd.span_sampling.mechanism"]);
}

echo "dropped_count for service+name: $droppedCount\n";

?>
--EXPECTF--
dropped_count for service only: 1
dropped_count for name only: 1
dropped_count for service+name: 1
