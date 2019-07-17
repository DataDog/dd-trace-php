--TEST--
Coms test communications implementation
--SKIPIF--
<?php
if (!getenv("DD_AGENT_HOST")) {
    die("skip test if agent host is not set");
}
?>
--ENV--
DD_TRACE_DEBUG_CURL_OUTPUT=1
DD_TRACE_AGENT_TIMEOUT=10000
DD_TRACE_AGENT_CONNECT_TIMEOUT=10000
--FILE--
<?php
    $spans = 10000;
    dd_trace_internal_fn('set_writer_send_on_flush', false);
    echo "DROP $spans BROKEN SPANS" . PHP_EOL;
    $incorrectSpan = ["incorrect_span"];
    for($i = 0; $i < $spans; $i++){
        dd_trace_buffer_span($incorrectSpan);
    }
    dd_trace_internal_fn('synchronous_flush');
    dd_trace_internal_fn('set_writer_send_on_flush', true);

    echo "FLUSH without SEND TO DROP SPANS" . PHP_EOL;
    $group_id = dd_trace_internal_fn('ddtrace_coms_next_group_id');
    echo "GROUP_ID " . $group_id . PHP_EOL;
    echo "NEXT GROUP_ID " . dd_trace_internal_fn('ddtrace_coms_next_group_id') . PHP_EOL;

    $span = [
        "trace_id" => 1589331357723252209,
        "span_id" => 1589331357723252209,
        "name" => "test_name",
        "resource" => "test_resource",
        "service" => "test_service",
        "start" => 1518038421211969000,
        "duration" => 1,
        "error" => 0,
    ];
    for($i =0 ; $i < $spans; $i++) {
        dd_trace_buffer_span($span);

        $span["span_id"]++;
    }

    echo "SPANS " . $spans . PHP_EOL;
    echo "SPAN_SIZE " . strlen(dd_trace_serialize_msgpack($span)) . PHP_EOL;

    dd_trace_internal_fn('shutdown_writer', true); //shuting down worker immediately will result in curl traces flush
?>
--EXPECTF--
DROP 10000 BROKEN SPANS
FLUSH without SEND TO DROP SPANS
GROUP_ID 2
NEXT GROUP_ID 3
SPANS 10000
SPAN_SIZE 127
{"rate_by_service":{"service:,env:":1}}
UPLOADED 1270%f bytes
