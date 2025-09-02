--TEST--
Check that the library config files overloads env vars
--SKIPIF--
<?php
copy(__DIR__.'/fleet_config.yaml', '/tmp/test_c_fleet_config_overloads_env.yaml');
?>
--ENV--
DD_SERVICE=service_from_env
DD_ENV=env_from_env
DD_DYNAMIC_INSTRUMENTATION_ENABLED=false
_DD_TEST_LIBRARY_CONFIG_FLEET_FILE=/tmp/test_c_fleet_config_overloads_env.yaml
_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE=/foo
--FILE--
<?php

function to_str($val) {
    return $val ? "true" : "false";
}

echo 'DD_SERVICE: '.dd_trace_env_config("DD_SERVICE")."\n";
echo 'DD_ENV: '.dd_trace_env_config("DD_ENV")."\n";

// System INI
echo 'DD_DYNAMIC_INSTRUMENTATION_ENABLED: '.to_str(dd_trace_env_config("DD_DYNAMIC_INSTRUMENTATION_ENABLED"))."\n";

dd_trace_internal_fn("finalize_telemetry");

?>
--EXPECT--
DD_SERVICE: service_from_fleet_config
DD_ENV: env_from_fleet_config
DD_DYNAMIC_INSTRUMENTATION_ENABLED: true
