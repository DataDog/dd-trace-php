--TEST--
[profiling] Allocation profiling should be disabled when JIT is active
--DESCRIPTION--
We did find a crash in PHP when collecting a stack sample in allocation
profiling when JIT is activated in a `ZEND_GENERATOR_RETURN`. For the time being
we make sure to disable allocation profiling when we detect the JIT is enabled.
--SKIPIF--
<?php
if (PHP_VERSION_ID >= 80208 || PHP_VERSION_ID >= 80121 && PHP_VERSION_ID < 80200)
    echo "skip: PHP Version >= 8.1.21 and >= 8.2.8 have a fix for this";
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
datadog.profiling.log_level=debug
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
--EXPECTF--
%aMemory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.1.21 or 8.2.8. See https://github.com/DataDog/dd-trace-php/pull/2088
%ADone.%a
