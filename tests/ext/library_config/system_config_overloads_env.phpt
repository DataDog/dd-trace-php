--TEST--
Check that the library config files update the configuration
--SKIPIF--
<?php
copy(__DIR__.'/default_config.yaml', '/tmp/test_c_system_config_overloads_env.yaml');
?>
--ENV--
DD_DYNAMIC_INSTRUMENTATION_ENABLED=false
_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE=/tmp/test_c_system_config_overloads_env.yaml
_DD_TEST_LIBRARY_CONFIG_FLEET_FILE=/foo
--FILE--
<?php

function to_str($val) {
    return $val ? "true" : "false";
}

echo 'DD_DYNAMIC_INSTRUMENTATION_ENABLED: '.to_str(dd_trace_env_config("DD_DYNAMIC_INSTRUMENTATION_ENABLED"))."\n";

?>
--EXPECT--
DD_DYNAMIC_INSTRUMENTATION_ENABLED: true
