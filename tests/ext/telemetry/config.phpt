--TEST--
Report user config telemetry
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTOFINISH_SPANS=1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_AGENT_HOST=
DD_AUTOLOAD_NO_COMPILE=
--INI--
datadog.trace.agent_url="file://{PWD}/config-telemetry.out"
--FILE--
<?php

DDTrace\start_span();

include __DIR__ . '/vendor/autoload.php';

DDTrace\close_span();

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/config-telemetry.out')) {
        foreach (file(__DIR__ . '/config-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                if ($json && $json["request_type"] == "app-started" && $json["application"]["service_name"] != "background_sender-php-service") {
                    $cfg = $json["payload"]["configuration"];
                    print_r(array_values(array_filter($cfg, function($c) {
                        return $c["origin"] == "EnvVar" && $c["name"] != "trace.sources_path" && $c["name"] != "trace.sidecar_trace_sender";
                    })));
                    var_dump(count(array_filter($cfg, function($c) {
                        return $c["origin"] == "Default";
                    })) > 100); // all the configs, no point in asserting them all here
                    var_dump(count(array_filter($cfg, function($c) {
                        return $c["origin"] != "Default" && $c["origin"] != "EnvVar";
                    }))); // all other configs
                    break 2;
                }
            }
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
            [name] => trace.agent_url
            [value] => file://%s/config-telemetry.out
            [origin] => EnvVar
        )

    [1] => Array
        (
            [name] => trace.cli_enabled
            [value] => 1
            [origin] => EnvVar
        )

    [2] => Array
        (
            [name] => instrumentation_telemetry_enabled
            [value] => 1
            [origin] => EnvVar
        )

    [3] => Array
        (
            [name] => trace.generate_root_span
            [value] => 0
            [origin] => EnvVar
        )

    [4] => Array
        (
            [name] => trace.git_metadata_enabled
            [value] => 0
            [origin] => EnvVar
        )

)
bool(true)
int(0)
--CLEAN--
<?php

@unlink(__DIR__ . '/config-telemetry.out');
