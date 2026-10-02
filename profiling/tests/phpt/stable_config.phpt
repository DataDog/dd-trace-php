--TEST--
Check the library config files
--SKIPIF--
<?php
copy(__DIR__.'/stable_config.yaml', '/tmp/test_profiling_stable_config.yaml');
$environment = [];
foreach (['DD_SERVICE', 'DD_ENV', 'DD_PROFILING_ENABLED', 'DD_TRACE_ENABLED'] as $name) {
    $value = getenv($name);
    $environment[] = $name . '=' . ($value === false ? 'unset' : ($value === '' ? 'empty' : ($name === 'DD_PROFILING_ENABLED' ? $value : 'set')));
}
echo 'info stable config skipif: fixture=' . (is_readable('/tmp/test_profiling_stable_config.yaml') ? 'readable' : 'unreadable')
    . ', local path=' . (getenv('_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE') ?: 'unset')
    . ', ' . implode(', ', $environment);
?>
--ENV--
_DD_TEST_LIBRARY_CONFIG_FLEET_FILE=/foo
_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE=/tmp/test_profiling_stable_config.yaml
--FILE--
<?php

echo 'DD_SERVICE: '.ini_get("datadog.service")."\n";
echo 'DD_ENV: '.ini_get("datadog.env")."\n";
echo 'DD_PROFILING_ENABLED: '.ini_get("datadog.profiling.enabled")."\n";

// This runs in a different PHP process from SKIPIF. Only report diagnostics
// when the configuration is wrong, so the passing test's output is unchanged.
if (ini_get('datadog.service') !== 'service_from_local_config'
    || ini_get('datadog.env') !== 'env_from_local_config'
    || ini_get('datadog.profiling.enabled') !== '0') {
    $path = getenv('_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE');
    $readable = $path !== false && is_readable($path);
    echo 'FILE diagnostic: local path=' . ($path === false ? 'unset' : $path)
        . ', fixture=' . ($readable ? 'readable' : 'unreadable')
        . ', sha256=' . ($readable ? hash_file('sha256', $path) : 'unavailable') . "\n";
    foreach (['DD_SERVICE', 'DD_ENV', 'DD_PROFILING_ENABLED', 'DD_TRACE_ENABLED'] as $name) {
        $value = getenv($name);
        echo 'FILE diagnostic: ' . $name . '='
            . ($value === false ? 'unset' : ($value === '' ? 'empty' : (in_array($name, ['DD_PROFILING_ENABLED', 'DD_TRACE_ENABLED'], true) ? $value : 'set')))
            . "\n";
    }
}

?>
--EXPECT--
DD_SERVICE: service_from_local_config
DD_ENV: env_from_local_config
DD_PROFILING_ENABLED: 0
