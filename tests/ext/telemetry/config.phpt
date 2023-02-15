--TEST--
Report user config telemetry
--ENV--
DD_TRACE_AGENT_URL=file://{PWD}/config-telemetry.out
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTOFINISH_SPANS=1
--FILE--
<?php

DDTrace\start_span();

include __DIR__ . '/vendor/autoload.php';

DDTrace\close_span();

dd_trace_internal_fn("finalize_telemetry");

usleep(100000);
foreach (file(__DIR__ . '/config-telemetry.out') as $l) {
    if ($l) {
    var_dump($l);
        $json = json_decode($l, true);
        if ($json["request_type"] == "app-config") {
            print_r($json["payload"]);
        }
    }
}

?>
--EXPECT--
Included
Array
(
    [config] => Array
        (
            [0] => Array
                (
                    // TBD
                )

        )

)
--CLEAN--
<?php

@unlink(__DIR__ . '/config-telemetry.out');
