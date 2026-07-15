--TEST--
[profiling] heap-live prefix epoch is established for ext-parallel threads
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400)
    echo "skip: prefix epoch PoC requires PHP 8.4+\n";
if (!PHP_ZTS)
    echo "skip: test requires PHP ZTS\n";
if (!extension_loaded('parallel'))
    echo "skip: test requires ext-parallel\n";
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_HEAP_LIVE_ENABLED=yes
DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE=1
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=no
--INI--
opcache.jit=off
--FILE--
<?php

$runtimes = $futures = [];
for ($worker = 0; $worker < 8; $worker++) {
    $runtimes[] = $runtime = new parallel\Runtime();
    $futures[] = $runtime->run(function () {
        $objects = [];
        for ($i = 0; $i < 2048; $i++) {
            $objects[] = new stdClass();
        }

        $string = '';
        for ($i = 0; $i < 32; $i++) {
            $string .= str_repeat('x', 1024);
        }

        $result = count($objects) + strlen($string);
        unset($objects, $string);

        return $result;
    });
}

foreach ($futures as $future) {
    if ($future->value() !== 34816) {
        throw new Exception('Unexpected worker result');
    }
}
echo "Done.\n";

?>
--EXPECT--
Done.
