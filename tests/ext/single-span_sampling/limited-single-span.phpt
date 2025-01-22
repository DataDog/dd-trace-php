--TEST--
Test max_per_second single span limiting
--SKIPIF--
<?php if (getenv("USE_ZEND_ALLOC") === "0" && !getenv("SKIP_ASAN")) die('skip timing sensitive test, does not make sense with valgrind'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_SAMPLE_RATE=0
DD_SPAN_SAMPLING_RULES=[{"sample_rate":1,"max_per_second":3}]
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

for ($i = 0; $i < 3; ++$i) {
    DDTrace\start_span();
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
}

echo "mechanism after 3: ", $last["_dd.span_sampling.mechanism"], "\n";

for ($i = 0; $i < 12; ++$i) {
    DDTrace\start_span();
    DDTrace\close_span();

    $last = dd_trace_serialize_closed_spans()[0]["metrics"];
}

echo "sampling present after 12: ";
var_dump(isset($last["_dd.span_sampling.mechanism"]));

?>
--EXPECT--
mechanism after 3: 8
sampling present after 12: bool(false)
