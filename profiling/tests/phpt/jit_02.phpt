--TEST--
[profiling] Allocation profiling should be enabled when JIT is active on fixed PHP version
--DESCRIPTION--
We did find a crash in PHP when collecting a stack sample in allocation
profiling when JIT is activated in a `ZEND_GENERATOR_RETURN` on PHP 8.0, 8.1.0-8.1.20 and 8.2.0-8.2.7.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80120 || PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80207)
    echo "skip: unpatched PHP version, so JIT should be inactive";
if (PHP_VERSION_ID < 80000)
    echo "skip: JIT requires PHP >= 8.0", PHP_EOL;
if (PHP_VERSION_ID >= 80300)
    echo "skip: not affected version", PHP_EOL;
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
$arch = php_uname('m');
if (PHP_VERSION_ID < 80100 && in_array($arch, ['aarch64', 'arm64']))
    echo "skip: JIT not available on aarch64 on PHP 8.0", PHP_EOL;
?>
--INI--
datadog.profiling.enabled=yes
datadog.profiling.log_level=trace
datadog.profiling.allocation_enabled=yes
datadog.profiling.experimental_cpu_time_enabled=no
zend_extension=opcache
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=4M
--FILE--
<?php
echo "Done.", PHP_EOL;
?>
--EXPECTREGEX--
.*Memory allocation profiling enabled.
.*Done.
.*
