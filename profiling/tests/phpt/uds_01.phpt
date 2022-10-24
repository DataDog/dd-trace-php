--TEST--
[profiling] invalid UDS socket path falls back to DD_AGENT_HOST
--DESCRIPTION--
This test verifies that an invalid Unix Domain Socket (UDS) path used in
DD_TRACE_AGENT_URL will cause it to fall back to DD_AGENT_HOST if set.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
  echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=no
DD_TRACE_AGENT_URL=unix:///invalid/path/to/apm.socket
DD_AGENT_HOST=ddagent
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

$key = "Profiling Agent Endpoint";
$value = "http://ddagent:8126/";

assert($values[$key] == $value, "Expected {$values[$key]} == {$value}");

echo "Done.";

?>
--EXPECT--
Done.
