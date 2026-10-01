--TEST--
The V1 chunk sampling_mechanism takes the digits after the last '-' of _dd.p.dm, and is unset when unparseable
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php

foreach (['-4', '934086a686-4', '-12', '-abc', '4-', '-'] as $dm) {
    DDTrace\start_trace_span();
    DDTrace\consume_distributed_tracing_headers([
        'x-datadog-trace-id' => '42',
        'x-datadog-parent-id' => '10',
        'x-datadog-sampling-priority' => '1',
        'x-datadog-tags' => "_dd.p.dm=$dm",
    ]);
    DDTrace\close_span();
    $span = dd_trace_serialize_closed_spans()[0];
    echo json_encode($dm), " => ", $span['sampling_mechanism'] ?? 'unset', "\n";
}

?>
--EXPECT--
"-4" => 4
"934086a686-4" => 4
"-12" => 12
"-abc" => unset
"4-" => unset
"-" => unset
