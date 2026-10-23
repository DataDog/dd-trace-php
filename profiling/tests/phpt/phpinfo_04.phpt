--TEST--
[profiling] test profiler's extension info with active JIT on patched version
--DESCRIPTION--
The profiler's phpinfo section contains important debugging information. This
test verifies that certain information is present.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80120 || PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80207 || PHP_VERSION_ID >= 80400)
    die("skip: unpatched PHP version, so JIT should be inactive");
if (PHP_VERSION_ID < 80000)
    die("skip: JIT requires PHP >= 8.0" . PHP_EOL);
if (!extension_loaded('datadog-profiling'))
    die("skip: test requires datadog-profiling" . PHP_EOL);
$arch = php_uname('m');
if (PHP_VERSION_ID < 80100 && in_array($arch, ['aarch64', 'arm64']))
    die("skip: JIT not available on aarch64 on PHP 8.0" . PHP_EOL);

if (PHP_ZTS && PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80200
    && php_uname('m') === 'x86_64' && file_exists('/lib/ld-musl-x86_64.so.1')) {
    echo "xfail PHP 8.0/8.1 lack the musl JIT TLS fix https://github.com/php/php-src/pull/13329", PHP_EOL;
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
    ["Allocation Profiling Enabled", "true"],
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
