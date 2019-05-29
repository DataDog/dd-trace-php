--TEST--
Coms test communications implementation
--SKIPIF--
<?php
if (!getenv("DD_AGENT_HOST")) {
    die("skip test if agent host is not set");
}
?>
--FILE--
<?php
    putenv("DD_TRACE_DEBUG_CURL_OUTPUT=1");
    $spans = 10000;
    for($i = 0; $i < $spans; $i++){
        dd_trace_coms_flush_span(0, "dropped_span");
    }
    dd_trace_internal_fn('set_writer_send_on_flush', false);
    dd_trace_coms_trigger_writer_flush();
    echo "FLUSH without SEND" . PHP_EOL;
    usleep(50); // sleep to avoid flaky tests

    $group_id = dd_trace_coms_next_span_group_id();
    echo "GROUP_ID " . $group_id . PHP_EOL;
    echo "NEXT GROUP_ID " . dd_trace_coms_next_span_group_id() . PHP_EOL;
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
        dd_trace_coms_flush_span(0, $encoded);
    }

    echo "SPANS " . $spans . PHP_EOL;
    echo "SPAN_SIZE " . strlen(dd_trace_serialize_msgpack($span)) . PHP_EOL;

    dd_trace_internal_fn('set_writer_send_on_flush', true);
    dd_trace_internal_fn('shutdown_writer', true); //shuting down worker immediately will result in curl traces flush
?>
--EXPECTF--
FLUSH without SEND
GROUP_ID 1
NEXT GROUP_ID 2
SPANS 10000
SPAN_SIZE 127
{"rate_by_service":{"service:,env:":1}}
UPLOADED 1270%d bytes
