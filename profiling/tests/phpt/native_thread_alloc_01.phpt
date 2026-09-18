--TEST--
[profiling] allocation profiling should not crash when allocation happens on non-PHP thread (ext-grpc compatibility)
--DESCRIPTION--
This test simulates what ext-grpc does: it creates a native thread (not a PHP thread) and triggers allocation profiling on it. The native thread fills PHP runtime cache slots with pointers owned by the profiler's string cache. After that thread exits, the main thread reuses those slots. The string cache must therefore follow PHP globals rather than the native thread's Rust TLS lifetime.
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
