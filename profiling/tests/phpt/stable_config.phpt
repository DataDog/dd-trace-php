--TEST--
Check the library config files
--SKIPIF--
<?php
copy(__DIR__.'/stable_config.yaml', '/tmp/test_profiling_stable_config.yaml');
@unlink('/tmp/test_profiling_stable_config.log');
$environment = [];
foreach (['DD_SERVICE', 'DD_ENV', 'DD_PROFILING_ENABLED', 'DD_TRACE_ENABLED'] as $name) {
    $value = getenv($name);
    $environment[] = $name . '=' . ($value === false ? 'unset' : ($value === '' ? 'empty' : ($name === 'DD_PROFILING_ENABLED' ? $value : 'set')));
}
echo 'info stable config skipif: fixture=' . (is_readable('/tmp/test_profiling_stable_config.yaml') ? 'readable' : 'unreadable')
    . ', local path=' . (getenv('_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE') ?: 'unset')
    . ', ' . implode(', ', $environment);
?>
--INI--
log_errors=1
error_log=/tmp/test_profiling_stable_config.log
--ENV--
_DD_TEST_LIBRARY_CONFIG_FLEET_FILE=/foo
_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE=/tmp/test_profiling_stable_config.yaml
_DD_TEST_LIBRARY_CONFIG_DEBUG=1
DD_TRACE_LOG_LEVEL=debug
DD_TRACE_LOG_FILE=/tmp/test_profiling_stable_config.log
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
    $log = '/tmp/test_profiling_stable_config.log';
    echo 'FILE diagnostic: log=' . (is_readable($log) ? 'readable' : 'unreadable') . "\n";
    if (is_readable($log)) {
        foreach (file($log) as $line) {
            if (stripos($line, 'stable configuration') !== false) {
                echo 'FILE diagnostic: ' . trim($line) . "\n";
            }
        }
    }
}

?>
--EXPECT--
DD_SERVICE: service_from_local_config
DD_ENV: env_from_local_config
DD_PROFILING_ENABLED: 0
