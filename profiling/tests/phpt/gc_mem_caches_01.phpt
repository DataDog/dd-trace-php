--TEST--
[profiling] test allocation profiling not blocking `gc_mem_caches()` call
--DESCRIPTION--
Directly after rinit, the `gc_mem_caches()` function is able to cleanup some
60KB of data, but with a custom allocator installed in the ZendMM it won't do
anything and return `int(0)`. As we do prepare the heap for this call, the
function should actually cleanup some memory and return the amount in bytes.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_ALLOCATION_ENABLED=yes
DD_SERVICE=datadog-profiling-phpt
DD_ENV=dev
DD_VERSION=13
DD_AGENT_HOST=localh0st
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_URL=http://datadog:8126
--FILE--
<?php

$cleaned = gc_mem_caches();

if ($cleaned == 0) {
    die('could not clean ZendMM');
}

echo 'Done.';

?>
--EXPECT--
Done.
