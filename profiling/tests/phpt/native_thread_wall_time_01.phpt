--TEST--
[profiling] wall-time profiling survives PHP executed on a native thread
--DESCRIPTION--
Simulates ext-grpc executing PHP on a native thread. A wall-time sample on that
thread caches pointers to thread-local strings in Zend's shared runtime cache.
The native thread then exits. Running the same PHP function on the main thread
reuses those pointers after their thread-local owner has been destroyed.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
  die("skip: test requires datadog-profiling");
if (PHP_ZTS)
  die("skip: test only applies to NTS builds");
if (PHP_VERSION_ID < 80000)
  die("skip: profiler runtime cache requires PHP 8+");
if (!function_exists('Datadog\Profiling\run_on_native_thread'))
  die("skip: test function not available (requires build with CFG_TEST)");
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=no
--FILE--
<?php
function sampled_callback(): void
{
    for ($i = 0; $i < 100; $i++) {
        usleep(10_000);
    }
}

var_dump(Datadog\Profiling\run_on_native_thread('sampled_callback'));
sampled_callback();
echo "Done.\n";
?>
--EXPECT--
bool(true)
Done.
