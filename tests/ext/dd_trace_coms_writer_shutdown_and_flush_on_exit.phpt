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
--FILE--
<?php
    $spans = 100;
    echo "FLUSH without SEND" . PHP_EOL;

    $cnt = 1589331357723252210;

    $spansToFlush = [];
    for($i =0 ; $i < $spans; $i++) {
        $span = [
            "trace_id" => 1589331357723252209,
            "span_id" => $cnt + $i,
            "name" => "test_name",
            "resource" => "test_resource",
            "service" => "test_service",
            "start" => 1518038421211969000,
            "duration" => 1,
            "error" => 0,
        ];

        dd_trace_buffer_span($span);
    }

    echo "SPANS " . $spans . PHP_EOL;
?>
--EXPECTF--
FLUSH without SEND
SPANS 100
{"rate_by_service":{"service:,env:":1}}
UPLOADED 127%d bytes
