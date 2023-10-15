--TEST--
[profiling] allocation profiling not crashing with system allocator in `gc_mem_caches()` call
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
if (PHP_VERSION_ID < 70400)
    echo "skip: 'run-tests.php' in PHP older then 7.4.0 overwrites the `USE_ZEND_ALLOC` environment variable";
?>
--ENV--
USE_ZEND_ALLOC=0
DD_PROFILING_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_LOG_LEVEL=off
DD_SERVICE=datadog-profiling-phpt
DD_ENV=dev
DD_VERSION=13
DD_AGENT_HOST=localh0st
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_URL=http://datadog:8126
--FILE--
<?php

$cleaned = gc_mem_caches();

if ($cleaned !== 0) {
    die('Cleaned '.$cleaned.' bytes, which is unexpected, maybe `USE_ZEND_ALLOC=0` was somehow overwritten?');
}

echo 'Done.';

?>
--EXPECT--
Done.
