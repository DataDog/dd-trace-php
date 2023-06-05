--TEST--
[profiling] test garbage collection being sampled for timeline
--DESCRIPTION--
This will check if for every garbage collection event a debug print will happen,
indicating that gc events in the engine are sampled for the timeline feature.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
if (ini_get('datadog.profiling.experimental_timeline_enabled') === false)
    echo "skip: datadog profiler is compiled without timeline support\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_TIMELINE_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=no
DD_PROFILING_LOG_LEVEL=debug
--FILE--
<?php

class SomeClass {
    public SomeClass $someProperty;
}

function generateGarbage() {
    $array = [];

    // Generate 10,001 objects as this will force two engine gc runs
    for ($i = 0; $i < 10001; $i++) {
        $a = new SomeClass();
        $b = new SomeClass();
        $a->someProperty = $b;
        $b->someProperty = $a;
        $array[] = $a;
        $array[] = $b;
    }
    unset($array);
    gc_collect_cycles();
}

generateGarbage();

echo 'Done.';

?>
--EXPECTREGEX--
.* Garbage collection with reason "engine" took [0-9]+ ns
.* Garbage collection with reason "engine" took [0-9]+ ns
.* Garbage collection with reason "induced" took [0-9]+ ns
Done..*
