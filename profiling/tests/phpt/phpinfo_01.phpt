--TEST--
[profiling] test profiler's extension info
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
DD_PROFILING_LOG_LEVEL=info
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=yes
DD_SERVICE=datadog-profiling-phpt
DD_ENV=dev
DD_VERSION=13
DD_AGENT_HOST=localh0st
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_URL=http://datadog:8126
--INI--
assert.exception=1
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
    ["Experimental CPU Time Profiling Enabled", "true"],
    ["Profiling Log Level", "info"],
    ["Profiling Agent Endpoint", "http://datadog:8126/"],
    ["Application's Environment (DD_ENV)", "dev"],
    ["Application's Service (DD_SERVICE)", "datadog-profiling-phpt"],
    ["Application's Version (DD_VERSION)", "13"],
];

foreach ($sections as list($key, $value)) {
    assert($values[$key] == $value, "Expected {$values[$key]} == {$value}");
}

echo "Done.";

?>
--EXPECT--
Done.
