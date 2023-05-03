--TEST--
Report user config telemetry
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
?>
--CONFLICTS--
all
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTOFINISH_SPANS=1
DD_AGENT_HOST=
--INI--
datadog.trace.agent_url=file://{PWD}/config-telemetry.out
--FILE--
<?php

DDTrace\start_span();

include __DIR__ . '/vendor/autoload.php';

DDTrace\close_span();

dd_trace_internal_fn("finalize_telemetry");

usleep(100000);
foreach (file(__DIR__ . '/config-telemetry.out') as $l) {
    if ($l) {
        $json = json_decode($l, true);
        if ($json["request_type"] == "app-started") {
            print_r($json["payload"]["configuration"]);
        }
    }
}

?>
--EXPECTF--
Included
Array
(
    [0] => Array
        (
            [name] => datadog.trace.agent_url
            [value] => file:///%s/config-telemetry.out
            [origin] => EnvVar
        )

    [1] => Array
        (
            [name] => datadog.trace.cli_enabled
            [value] => 1
            [origin] => EnvVar
        )

    [2] => Array
        (
            [name] => datadog.trace.generate_root_span
            [value] => 0
            [origin] => EnvVar
        )

)
--CLEAN--
<?php

@unlink(__DIR__ . '/config-telemetry.out');
