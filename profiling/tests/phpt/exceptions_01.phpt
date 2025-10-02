--TEST--
[profiling] test exceptions being sampled
--DESCRIPTION--
This test will check that exceptions are being sampled and that a custom
sampling rate will actually be used.
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
DD_PROFILING_EXPERIMENTAL_EXCEPTION_ENABLED=true
DD_PROFILING_EXPERIMENTAL_EXCEPTION_SAMPLING_DISTANCE=20
DD_PROFILING_ALLOCATION_ENABLED=no
DD_PROFILING_LOG_LEVEL=trace
--FILE--
<?php

function generateExceptions() {
    for ($i = 0; $i <= 100; $i++) {
        try {
            // two labels: "thread_id" and "exception type"
            throw new \RuntimeException();
        } catch (\RuntimeException $e) {
            # I don't care :-P
        }
    }
}

generateExceptions();

echo 'Done.';

?>
--EXPECTREGEX--
.* Exception profiling initialized with sampling distance: 20
.* Sent Exception { count: 1 } of 2 frames, 3 labels with Exception RuntimeException to profiler.
.*Done\..*
.*
