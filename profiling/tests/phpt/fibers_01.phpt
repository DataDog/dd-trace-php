--TEST--
[profiling] test that the profiler doesn't crash when fibers are used
--DESCRIPTION--
At this point we do not have active support for fibers, but we at least want to
make sure to not crash when fibers are in use
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
if (PHP_VERSION_ID < 80100)
    echo "skip: php 8.1 or above is required for fibers.\n";
?>
--INI--
datadog.profiling.enabled=yes
datadog.profiling.log_level=debug
datadog.profiling.allocation_enabled=no
datadog.profiling.experimental_cpu_time_enabled=no
--FILE--
<?php
$fiber = new Fiber(function (): void {
    usleep(20000);
    $value = Fiber::suspend('fiber');
    usleep(20000);
    echo "Value used to resume fiber: ", $value, PHP_EOL;
});

usleep(20000);
$value = $fiber->start();
usleep(20000);
echo "Value from fiber suspending: ", $value, PHP_EOL;
usleep(20000);
$fiber->resume('test');
?>
--EXPECTREGEX--
.*
Value from fiber suspending: fiber
Value used to resume fiber: test
.*
