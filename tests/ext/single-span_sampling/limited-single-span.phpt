--TEST--
Test max_per_second single span limiting
--ENV--
DD_SAMPLING_RATE=0
DD_SPAN_SAMPLING_RULES=[{"sample_rate":1,"max_per_second":10}]
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

for ($i = 0; $i < 10; ++$i) {
    DDTrace\start_span();
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
}

echo "mechanism after 10: ", $last["_dd.span_sampling.mechanism"], "\n";

for ($i = 0; $i < 30; ++$i) {
    DDTrace\start_span();
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
}

echo "sampling present after 30: ";
var_dump(isset($last["_dd.span_sampling.mechanism"]));

?>
--EXPECT--
mechanism after 10: 8
sampling present after 30: bool(false)
