--TEST--
Check the library config files
--SKIPIF--
<?php
copy(__DIR__.'/default_config.yaml', '/tmp/test_c_default_config.yaml');
?>
--ENV--
_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE=/tmp/test_c_default_config.yaml
_DD_TEST_LIBRARY_CONFIG_FLEET_FILE=/foo
--FILE--
<?php

echo 'DD_SERVICE: '.dd_trace_env_config("DD_SERVICE")."\n";
echo 'DD_ENV: '.dd_trace_env_config("DD_ENV")."\n";

?>
--EXPECT--
DD_SERVICE: service_default_config
DD_ENV: env_from_default_config
