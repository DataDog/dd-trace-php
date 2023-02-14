--TEST--
Simple telemetry test
--ENV--
DD_TRACE_AGENT_URL=file://{PWD}/simple-telemetry.out
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\start_span();
DDTrace\close_span();

dd_trace_internal_fn("finalize_telemetry");

usleep(100000);
foreach (file(__DIR__ . '/simple-telemetry.out') as $l) {
    if ($l) {
        $json = json_decode($l, true);
        if ($json["request_type"] == "app-started" || $json["request_type"] == "app-closing") {
            var_dump($json["request_type"]);
        }
    }
}

?>
--EXPECT--
string(11) "app-started"
string(11) "app-closing"
--CLEAN--
<?php

@unlink(__DIR__ . '/simple-telemetry.out');
