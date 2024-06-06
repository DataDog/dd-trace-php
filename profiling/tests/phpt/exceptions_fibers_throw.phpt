--TEST--
[profiling] test exceptions being sampled in throwing fibers
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
if (strpos($info, 'Exception Profiling Enabled') === false)
    echo "skip: datadog profiler is compiled without exception profiling support\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=no
DD_PROFILING_EXPERIMENTAL_TIMELINE_ENABLED=no
DD_PROFILING_EXPERIMENTAL_EXCEPTION_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_EXCEPTION_SAMPLING_DISTANCE=1
DD_PROFILING_ALLOCATION_ENABLED=no
--FILE--
<?php

// Must not crash
for ($i = 0; $i < 100; ++$i) {
    try {
        $fiber = new Fiber(
            function() {
                try {
                    Fiber::suspend(1);
                } catch(Throwable $e) {
                }
            }
        );

        $fiber->start();

        $fiber->throw(new Error);
    } catch (Throwable $e) {
        # I care even less than in the other test
    }
}

echo 'Done.';

?>
--EXPECT--
Done.
