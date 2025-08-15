--TEST--
Check the library config files
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') {
    die("skip: pecl run-tests does not support {PWD}");
}
if (PHP_OS === "WINNT" && PHP_VERSION_ID < 70400) {
    die("skip: Windows on PHP 7.2 and 7.3 have permission issues with synchronous access to telemetry");
}
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) {
    die('skip timing sensitive test - valgrind is too slow');
}
require __DIR__ . '/../includes/clear_skipif_telemetry.inc';
copy(__DIR__.'/local_config.yaml', '/tmp/test_c_local_config.yaml');
?>
--ENV--
_DD_TEST_LIBRARY_CONFIG_FLEET_FILE=/foo
_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE=/tmp/test_c_local_config.yaml
DD_TRACE_SPANS_LIMIT=42
--INI--
datadog.trace.agent_url="file://{PWD}/local-config-telemetry.out"
--FILE--
<?php

function to_str($val)
{
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
    if (file_exists(__DIR__ . '/local-config-telemetry.out')) {
        foreach (file(__DIR__ . '/local-config-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $json) {
                    if ($json["request_type"] == "app-client-configuration-change") {
                        $cfg = $json["payload"]["configuration"];

                        // Hack: On PHP <= 7.3, another PHP process is sending telemetry data
                        // before the stable config file is taken into account.
                        if (PHP_MAJOR_VERSION == 7 && PHP_MINOR_VERSION <= 3) {
                            if (strpos($l, '42_local_config') === false) {
                                continue;
                            }
                        }

                        var_dump(array_values(array_filter($cfg, function ($c) {
                            return in_array($c["name"], ['service', 'env', 'dynamic_instrumentation.enabled', 'trace.spans_limit', 'trace.generate_root_span']);
                        })));
                        break 3;
                    }
                }
            }
        }
    }
}

?>
--EXPECT--
DD_SERVICE: service_from_local_config
DD_ENV: env_from_local_config
DD_DYNAMIC_INSTRUMENTATION_ENABLED: true
------ Telemetry ------
array(5) {
  [0]=>
  array(4) {
    ["name"]=>
    string(3) "env"
    ["value"]=>
    string(21) "env_from_local_config"
    ["origin"]=>
    string(19) "local_stable_config"
    ["config_id"]=>
    string(15) "42_local_config"
  }
  [1]=>
  array(4) {
    ["name"]=>
    string(7) "service"
    ["value"]=>
    string(25) "service_from_local_config"
    ["origin"]=>
    string(19) "local_stable_config"
    ["config_id"]=>
    string(15) "42_local_config"
  }
  [2]=>
  array(4) {
    ["name"]=>
    string(24) "trace.generate_root_span"
    ["value"]=>
    string(4) "true"
    ["origin"]=>
    string(7) "default"
    ["config_id"]=>
    NULL
  }
  [3]=>
  array(4) {
    ["name"]=>
    string(17) "trace.spans_limit"
    ["value"]=>
    string(2) "42"
    ["origin"]=>
    string(7) "env_var"
    ["config_id"]=>
    NULL
  }
  [4]=>
  array(4) {
    ["name"]=>
    string(31) "dynamic_instrumentation.enabled"
    ["value"]=>
    string(4) "true"
    ["origin"]=>
    string(19) "local_stable_config"
    ["config_id"]=>
    string(15) "42_local_config"
  }
}
--CLEAN--
<?php

@unlink(__DIR__ . '/local-config-telemetry.out');
