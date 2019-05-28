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
    $data = [];
    for($i =0 ; $i < 60000; $i++) {
        $span["span_id"]++;
        $sp = $span;
        // $encoded = dd_trace_serialize_msgpack($span);
        // $str = $str . $encoded;
        $data []= $sp;
        dd_trace_internal_fn('flush_span', 0, $encoded);
    }
    // echo "done" ;

    echo strlen($encoded) . PHP_EOL;
    dd_trace_internal_fn('curl_ze_data_out');
?>
--EXPECT--
true
