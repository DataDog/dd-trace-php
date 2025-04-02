--TEST--
[profiling] test profiler's extension info (.ini version)
--DESCRIPTION--
The profiler's phpinfo section contains important debugging information. This
test verifies that certain information is present when configured by .ini.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--INI--
assert.exception=1
datadog.profiling.enabled=auto
--FILE--
<?php

ob_start();
$extension = new ReflectionExtension('datadog-profiling');
$extension->info();
$output = ob_get_clean();

$lines = preg_split("/\R/", $output);
$values = [];
foreach ($lines as $line) {
    $pair = explode("=>", $line, 2);
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
