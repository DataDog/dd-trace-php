--TEST--
Check the library config files
--SKIPIF--
<?php
copy(__DIR__.'/stable_config.yaml', '/tmp/test_profiling_stable_config.yaml');
?>
--ENV--
_DD_TEST_LIBRARY_CONFIG_FLEET_FILE=/foo
_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE=/tmp/test_profiling_stable_config.yaml
--FILE--
<?php

echo 'DD_SERVICE: '.ini_get("datadog.service")."\n";
echo 'DD_ENV: '.ini_get("datadog.env")."\n";
echo 'DD_PROFILING_ENABLED: '.ini_get("datadog.profiling.enabled")."\n";

?>
--EXPECT--
DD_SERVICE: service_from_local_config
DD_ENV: env_from_local_config
DD_PROFILING_ENABLED: 0
