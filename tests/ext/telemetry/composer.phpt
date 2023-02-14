--TEST--
Read telemetry via composer
--ENV--
DD_TRACE_AGENT_URL=file://{PWD}/composer-telemetry.out
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\start_span();

include __DIR__ . '/vendor/autoload.php';

DDTrace\close_span();

dd_trace_internal_fn("finalize_telemetry");

usleep(100000);
foreach (file(__DIR__ . '/composer-telemetry.out') as $l) {
    if ($l) {
        $json = json_decode($l, true);
        if ($json["request_type"] == "app-dependencies-loaded") {
            print_r($json["payload"]);
        }
    }
}

?>
--EXPECT--
Included
Array
(
    [dependencies] => Array
        (
            [0] => Array
                (
                    [name] => datadog/dd-trace
                    [version] => dev-master
                    [type] => PlatformStandard
                )

        )

)
--CLEAN--
<?php

@unlink(__DIR__ . '/composer-telemetry.out');
