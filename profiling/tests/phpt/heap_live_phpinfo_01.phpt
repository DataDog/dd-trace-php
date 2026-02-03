--TEST--
[profiling] heap live profiling setting is shown in phpinfo
--DESCRIPTION--
Verify that the heap live profiling setting is properly displayed in phpinfo
when allocation profiling is enabled.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_HEAP_LIVE_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=no
--INI--
assert.exception=1
opcache.jit=off
--FILE--
<?php

ob_start();
$extension = new ReflectionExtension('datadog-profiling');
$extension->info();
$output = ob_get_clean();

$lines = preg_split("/\R/", $output);
$values = [];
foreach ($lines as $line) {
    $pair = explode("=>", $line);
    if (count($pair) != 2) {
        continue;
    }
    $values[trim($pair[0])] = trim($pair[1]);
}

// Check exact values
$sections = [
    ["Profiling Enabled", "true"],
    ["Allocation Profiling Enabled", "true"],
    ["Heap Live Profiling Enabled", "true"],
];

foreach ($sections as [$key, $expected]) {
    assert(
        $values[$key] === $expected,
        "Expected '{$expected}', found '{$values[$key]}', for key '{$key}'"
    );
}

echo "Done.";

?>
--EXPECT--
Done.
