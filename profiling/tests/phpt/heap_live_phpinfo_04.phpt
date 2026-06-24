--TEST--
[profiling] heap live profiling with active JIT
--DESCRIPTION--
Verify that heap live profiling is disabled if allocation profiling is disabled due to the JIT bug workaround,
and works normally otherwise.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
if (PHP_VERSION_ID < 80000)
    echo "skip: JIT requires PHP >= 8.0\n";

$affected = (PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80121)
    || (PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80208)
    || (PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80407);

if (!$affected) {
    echo "skip: JIT workaround is inactive on this PHP version\n";
}
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_HEAP_LIVE_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=no
--INI--
assert.exception=1
zend_extension=opcache
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=4M
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

$allocation_enabled = $values["Allocation Profiling Enabled"];
$heap_live_enabled = $values["Experimental Heap Live Profiling Enabled"];

var_dump($allocation_enabled);
var_dump($heap_live_enabled);

// If allocation profiling is not fully active, then heap live profiling MUST NOT be active
if ($allocation_enabled !== "true") {
    assert($heap_live_enabled === "false (requires allocation profiling)");
} else {
    assert($heap_live_enabled === "true");
}

echo "Done.";

?>
--EXPECTF--
string(%d) "%s"
string(%d) "%s"
Done.
