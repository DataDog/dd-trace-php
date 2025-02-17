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
opcache.jit=off
datadog.profiling.enabled=no
datadog.profiling.log_level=info
datadog.profiling.experimental_cpu_time_enabled=yes
datadog.profiling.allocation_enabled=yes
datadog.profiling.exception_enabled=yes
datadog.service=datadog-profiling-phpt
datadog.env=dev
datadog.version=13
datadog.agent_host=localh0st
datadog.trace.agent_port=80
datadog.trace.agent_url=http://datadog:8126
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
    ["Profiling Enabled", "false"],
    ["Experimental CPU Time Profiling Enabled", "false (profiling disabled)"],
    ["Allocation Profiling Enabled", "false (profiling disabled)"],
    ["Exception Profiling Enabled", "false (profiling disabled)"],
    ["I/O Profiling Enabled", "false (profiling disabled)"],
    ["Endpoint Collection Enabled", "false (profiling disabled)"],
    ["Profiling Log Level", "off (profiling disabled)"],
    ["Profiling Agent Endpoint", "http://datadog:8126/"],
    ["Application's Environment (DD_ENV)", "dev"],
    ["Application's Service (DD_SERVICE)", "datadog-profiling-phpt"],
    ["Application's Version (DD_VERSION)", "13"],
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