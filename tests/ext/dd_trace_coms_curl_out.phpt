--TEST--
Coms test parallel writer consistency
--FILE--
<?php
    putenv("DD_TRACE_DEBUG_CURL_OUTPUT=1");
    dd_trace_internal_fn('flush_span', 0, "dropped_span");
    dd_trace_internal_fn('set_writer_send_on_flush', false);
    dd_trace_internal_fn('trigger_writer_flush');
    echo "FLUSH without SEND" . PHP_EOL;
    usleep(50); // sleep to avoid flaky tests
    dd_trace_internal_fn('shutdown_writer', true, true); // close old worked immediately
    sleep(1);

    $group_id = dd_trace_internal_fn('next_span_group_id');
    echo "GROUP_ID " . $group_id . PHP_EOL;
    echo "NEXT GROUP_ID " . dd_trace_internal_fn('next_span_group_id') . PHP_EOL;
    $spans = 10000;
    $span = [
        "trace_id" => 1589331357723252209,
        "span_id" => 1589331357723252210,
        "name" => "test_name",
        "resource" => "test_resource",
        "service" => "test_service",
        "start" => 1518038421211969000,
        "duration" => 1,
        "error" => 0,
    ];
    for($i =0 ; $i < $spans; $i++) {
        $span["span_id"]++;
        $sp = $span;
        $encoded = dd_trace_serialize_msgpack($span);
        dd_trace_internal_fn('flush_span', 0, $encoded);
    }

    echo "SPANS " . $spans . PHP_EOL;
    echo "SPAN_SIZE " . strlen(dd_trace_serialize_msgpack($span)) . PHP_EOL;

    dd_trace_internal_fn('init_and_start_writer');
    dd_trace_internal_fn('shutdown_writer', true); //shuting down worker immediately will result in curl traces flush
?>
--EXPECT--
FLUSH without SEND
GROUP_ID 1
NEXT GROUP_ID 2
SPANS 10000
SPAN_SIZE 127
{"rate_by_service":{"service:,env:":1}}
UPLOADED 1270633 bytes
