--TEST--
[profiling] test allocation profiling not blocking `gc_mem_caches()` call
--DESCRIPTION--
With a custom allocator installed in the ZendMM, `gc_mem_caches()` won't do
anything on its own and would return `int(0)`. As we do prepare the heap for
this call, the function should actually cleanup some memory and return the
amount in bytes.

Relying on incidental garbage left over from RINIT (e.g. directly after
startup) is not robust: when the tracer is active in the same process (as it
is in the combined ddtrace.so build), the tracer's own request-lifetime
allocations (e.g. the root span) can consume exactly the small amount of
cached/free memory that would otherwise be reclaimable, causing this test to
fail for reasons unrelated to allocation profiling. Instead, this test
allocates and frees enough memory itself to guarantee there is something for
`zend_mm_gc()` to reclaim, independent of what any other loaded extension
leaves behind.
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0')
    die("skip requires ZendMM");
if (!(extension_loaded('datadog-profiling') || ini_get('datadog.profiling.enabled') !== false))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
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

// Guarantee there is a substantial amount of freed memory available for
// `zend_mm_gc()` to reclaim, regardless of any incidental allocations made by
// other extensions (e.g. an active tracer) during RINIT.
$garbage = [];
for ($i = 0; $i < 20000; $i++) {
    $garbage[] = str_repeat('x', 100);
}
$garbage = null;

$cleaned = gc_mem_caches();

if ($cleaned == 0) {
    die('could not clean ZendMM');
}

echo 'Done.';

?>
--EXPECT--
Done.
