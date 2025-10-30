--TEST--
[profiling] test exceptions being sampled
--DESCRIPTION--
This test will check that exceptions are being sampled and that a custom
sampling rate will actually be used.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
if (!extension_loaded('parallel'))
    echo "skip: test requires `ext-parallel`\n";
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

$generateExceptions = function(int $worker) {
    for ($i = 0; $i <= 100; $i++) {
        try {
            throw new \RuntimeException($worker);
        } catch (\RuntimeException $e) {
            # I don't care :-P
        }
    }
    return $worker;
};

for ($i = 0; $i < 10; $i++) {
    $runtime[$i] = new \parallel\Runtime();
    $future[$i] = $runtime[$i]->run($generateExceptions, [$i]);
}

for ($i = 0; $i < 10; $i++) {
    printf("\nWorker %s exited\n", $future[$i]->value());
}

echo 'Done.';

?>
--EXPECTREGEX--
.* Exception profiling initialized with sampling distance: 20
.* Sent Exception { count: 1 } of 1 frames, 3 labels with Exception RuntimeException to profiler.
.*Worker [0-9] exited
.*Done..*
.*
