--TEST--
[profiling] test exceptions being sampled in throwing generator
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
--FILE--
<?php

function primeGenerator($g) {
    $g->valid();
}
function spray() {}

// Must not crash
for ($i = 0; $i < 100; ++$i) {
    try {
        $g = (function() {
            yield;
        })();

        primeGenerator($g);

        spray(...range(1, 20));

        $g->throw(new Error);
    } catch (Error $e) {
        # I care even less than in the other test
    }
}

echo 'Done.';

?>
--EXPECT--
Done.
