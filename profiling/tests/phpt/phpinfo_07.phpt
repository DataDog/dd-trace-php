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
DD_PROFILING_ENABLED=yes
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

$tailcall_vm_interrupt_workaround_applies =
    PHP_VERSION_ID >= 80500 &&
    PHP_VERSION_ID < 80507 &&
    defined('ZEND_VM_KIND') &&
    ZEND_VM_KIND === 'ZEND_VM_KIND_TAILCALL';

$cpu_time_expected = $tailcall_vm_interrupt_workaround_applies
    ? "false"
    : "true (all experimental features enabled)";

// Check exact values for this set.
$sections = [
    ["Profiling Enabled", "true"],
    ["Profiling Experimental Features Enabled", "true"],
    ["Experimental CPU Time Profiling Enabled", $cpu_time_expected],
    ["Allocation Profiling Enabled", "false"],
    ["Exception Profiling Enabled", "false"],
    ["Timeline Enabled", "false"],
    ["I/O Profiling Enabled", "true"],
];

foreach ($sections as [$key, $expected]) {
    $actual = $values[$key] ?? null;
    assert(
        $actual === $expected,
        "Expected '{$expected}', found '{$actual}', for key '{$key}'"
    );
}

echo "Done.";

?>
--EXPECT--
Done.
