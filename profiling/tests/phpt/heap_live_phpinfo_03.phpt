--TEST--
[profiling] experimental heap live profiling is enabled via DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED
--DESCRIPTION--
Verify that setting the umbrella DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED=true
turns on heap live profiling without needing the specific opt-in.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED=yes
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

// Check exact values. The specific DD_PROFILING_EXPERIMENTAL_HEAP_LIVE_ENABLED
// is NOT set; only the umbrella flag is. We expect heap live to be on anyway.
$sections = [
    ["Profiling Enabled", "true"],
    ["Allocation Profiling Enabled", "true"],
    ["Experimental Heap Live Profiling Enabled", "true"],
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
