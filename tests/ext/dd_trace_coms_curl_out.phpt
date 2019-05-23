--TEST--
Coms test parallel writer consistency
--FILE--
<?php
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

    $encoded = dd_trace_serialize_msgpack($span);
    echo strlen($encoded) . PHP_EOL;
    $str = "";
    for($i =0 ; $i < 20000; $i++) {
        $span["span_id"]++;
        $encoded = dd_trace_serialize_msgpack($span);
        // $str = $str . $encoded;
        dd_trace_internal_fn('flush_span', 0, $encoded);
    }

    echo strlen($str) . PHP_EOL;
    echo (dd_trace_internal_fn('flush_span', 0, $encoded) ? 'true' : 'false') . PHP_EOL; // true if success writing 3 + 12 bytes on 64 bit platform

    dd_trace_internal_fn('curl_ze_data_out');
?>
--EXPECT--
true
