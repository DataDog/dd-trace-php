--TEST--
[profiling] test profiler's service when none is given
--DESCRIPTION--
When DD_SERVICE isn't provided, default to the script name.
Technically there is another fallback if there isn't a script name, but this
is hard to exercise because even for code over standard input, PHP sets a name
of "Standard input code."
This behavior matches the tracer's and should be kept in sync.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
  echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=no
DD_SERVICE=
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

$key = "Application's Service (DD_SERVICE)";
$value = basename(__FILE__);
assert($values[$key] == $value, "Expected {$values[$key]} == {$value}");

echo "Done.";

?>
--EXPECT--
Done.
