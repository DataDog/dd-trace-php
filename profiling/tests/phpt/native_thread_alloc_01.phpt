--TEST--
[profiling] allocation profiling should not crash when allocation happens on non-PHP thread (ext-grpc compatibility)
--DESCRIPTION--
This test simulates what ext-grpc does: it creates a native thread (not a PHP thread) and triggers memory allocation on it. Before the fix, this would crash because:
1. ThreadRng uses thread-local storage internally
2. ALLOCATION_PROFILING_STATS was thread-local
Both of these are uninitialized for non-PHP threads since they never went through GINIT. After the fix, NTS builds use a global static instead of TLS.
See https://github.com/DataDog/dd-trace-php/pull/3542 for the fix
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
  die("skip: test requires datadog-profiling");
if (PHP_ZTS)
  die("skip: test only applies to NTS builds");
if (!function_exists('Datadog\Profiling\run_alloc_on_native_thread'))
  die("skip: test function not available (requires build with CFG_TEST)");
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE=1
--FILE--
<?php
Datadog\Profiling\run_alloc_on_native_thread();
// failure case is a segfault, no need to check any return value ;-)
echo "Done.\n";
?>
--EXPECTF--
Done.
