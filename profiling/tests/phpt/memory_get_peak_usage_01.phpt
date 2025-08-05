--TEST--
[profiling] test allocation profiling not interfering with `memory_get_peak_usage()`
--DESCRIPTION--
https://github.com/DataDog/dd-trace-php/issues/3360
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0')
    die("skip requires ZendMM");
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=no
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_LOG_LEVEL=off
--FILE--
<?php
$x = str_repeat("a", 1024*1024);
var_dump(memory_get_peak_usage(false) > 0);
var_dump(memory_get_peak_usage(true) > 0);
echo 'Done.';
?>
--EXPECT--
bool(true)
bool(true)
Done.
