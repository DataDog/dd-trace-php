--TEST--
[profiling] test profiler's extension info with aliases
--DESCRIPTION--
This test shall validate that the "old" EXPERIMENTAL env variables get
overwritten by the new ones without EXPERIMENTAL in them.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=no
DD_PROFILING_EXPERIMENTAL_ALLOCATION_ENABLED=yes
DD_PROFILING_EXCEPTION_ENABLED=no
DD_PROFILING_EXPERIMENTAL_EXCEPTION_ENABLED=yes
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
    ["Profiling Enabled", "true"],
    ["Allocation Profiling Enabled", "false"],
    ["Exception Profiling Enabled", "false"],
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
