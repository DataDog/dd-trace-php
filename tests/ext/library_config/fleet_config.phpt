--TEST--
Check the library config files
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
require __DIR__ . '/../includes/clear_skipif_telemetry.inc';
copy(__DIR__.'/fleet_config.yaml', '/tmp/test_c_fleet_config.yaml');
?>
--ENV--
_DD_TEST_LIBRARY_CONFIG_FLEET_FILE=/tmp/test_c_fleet_config.yaml
_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE=/foo
DD_TRACE_SPANS_LIMIT=42
--INI--
datadog.trace.agent_url="file://{PWD}/config-telemetry.out"
--FILE--
<?php

function to_str($val) {
    return $val ? "true" : "false";
}

echo 'DD_SERVICE: '.dd_trace_env_config("DD_SERVICE")."\n";
echo 'DD_ENV: '.dd_trace_env_config("DD_ENV")."\n";

// System INI
echo 'DD_DYNAMIC_INSTRUMENTATION_ENABLED: '.to_str(dd_trace_env_config("DD_DYNAMIC_INSTRUMENTATION_ENABLED"))."\n";

echo "------ Telemetry ------\n";

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/config-telemetry.out')) {
        foreach (file(__DIR__ . '/config-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                if ($json && $json["request_type"] == "app-started" && $json["application"]["service_name"] != "background_sender-php-service" && $json["application"]["service_name"] != "datadog-ipc-helper") {
                    $cfg = $json["payload"]["configuration"];

                    print_r(array_values(array_filter($cfg, function($c) {
                        return in_array($c["name"], ['service', 'env', 'dynamic_instrumentation.enabled', 'trace.spans_limit', 'trace.generate_root_span']);
                    })));
                    break 2;
                }
            }
        }
    }
}

?>
--EXPECT--
DD_SERVICE: service_from_fleet_config
DD_ENV: env_from_fleet_config
DD_DYNAMIC_INSTRUMENTATION_ENABLED: true
------ Telemetry ------
Array
(
    [0] => Array
        (
            [name] => env
            [value] => env_from_fleet_config
            [origin] => fleet_stable_config
        )

    [1] => Array
        (
            [name] => service
            [value] => service_from_fleet_config
            [origin] => fleet_stable_config
        )

    [2] => Array
        (
            [name] => trace.generate_root_span
            [value] => true
            [origin] => default
        )

    [3] => Array
        (
            [name] => trace.spans_limit
            [value] => 42
            [origin] => env_var
        )

    [4] => Array
        (
            [name] => dynamic_instrumentation.enabled
            [value] => true
            [origin] => fleet_stable_config
        )

)
--CLEAN--
<?php

@unlink(__DIR__ . '/config-telemetry.out');
