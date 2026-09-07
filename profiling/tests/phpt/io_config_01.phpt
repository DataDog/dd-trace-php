--TEST--
[profiling] I/O profiling configuration is system-only
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--INI--
assert.exception=1
datadog.profiling.io_enabled=0
--FILE--
<?php

$config = ini_get_all();
foreach ([
    'datadog.profiling.io_enabled',
    'datadog.profiling.experimental_io_enabled',
] as $name) {
    assert($config[$name]['access'] === INI_SYSTEM);
    assert(ini_get($name) === '0');
    assert(ini_set($name, '1') === false);
    assert(ini_get($name) === '0');
}

echo "Done.\n";

?>
--EXPECT--
Done.
