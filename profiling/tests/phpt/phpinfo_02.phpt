--TEST--
[profiling] test profiler's extension info with active JIT
--DESCRIPTION--
The profiler's phpinfo section contains important debugging information. This
test verifies that certain information is present.
--SKIPIF--
<?php
if (PHP_VERSION_ID >= 80208 || PHP_VERSION_ID >= 80121 && PHP_VERSION_ID < 80200)
    echo "skip: PHP Version >= 8.1.21 and >= 8.2.8 have a fix for this";
if (PHP_VERSION_ID < 80000)
    echo "skip: JIT requires PHP >= 8.0", PHP_EOL;
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
$arch = php_uname('m');
if (PHP_VERSION_ID < 80100 && in_array($arch, ['aarch64', 'arm64']))
    echo "skip: JIT not available on aarch64 on PHP 8.0", PHP_EOL;

// TODO: remove once ZTS support for allocation profiling is done
if (PHP_ZTS) {
    echo "skip: not support on ZTS builds at the moment";
}
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_LOG_LEVEL=off
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=no
DD_PROFILING_ALLOCATION_ENABLED=yes
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

// Check exact values for this set
$sections = [
    ["Allocation Profiling Enabled", "Not available due to JIT being active, see https://github.com/DataDog/dd-trace-php/pull/2088 for more information."],
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
