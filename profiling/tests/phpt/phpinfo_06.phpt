--TEST--
[profiling] test profiler's extension info with experimental feature override
--DESCRIPTION--
The profiler's phpinfo section contains important debugging information. This
test verifies that certain information is present.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=no
DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED=yes
DD_PROFILING_LOG_LEVEL=off
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=no
DD_PROFILING_ALLOCATION_ENABLED=no
DD_PROFILING_EXCEPTION_ENABLED=no
DD_PROFILING_TIMELINE_ENABLED=no
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

// Check that Version exists, but not its value
assert(isset($values["Version"]));

// Check exact values for this set
$sections = [
    ["Profiling Enabled", "false"],
    ["Profiling Experimental Features Enabled", "false (profiling disabled)"],
    ["Experimental CPU Time Profiling Enabled", "false (profiling disabled)"],
    ["Allocation Profiling Enabled", "false (profiling disabled)"],
    ["Exception Profiling Enabled", "false (profiling disabled)"],
    ["Timeline Enabled", "false (profiling disabled)"],
    ["I/O Profiling Enabled", "false (profiling disabled)"],
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