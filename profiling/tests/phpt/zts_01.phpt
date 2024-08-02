--TEST--
[profiling] test that the profiler works in a ZTS build, loads and exists and does not segfault
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
if (!PHP_ZTS) {
    echo "skip: test requires PHP ZTS\n";
}
?>
--INI--
datadog.profiling.enabled=yes
datadog.profiling.log_level=debug
--FILE--
<?php
usleep(10000);
var_dump((bool)PHP_ZTS);
?>
--EXPECTREGEX--
.*Started with an upload period of 67 seconds and approximate wall-time period of 10 milliseconds.
.*bool\(true\)
.*
