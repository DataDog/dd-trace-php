--TEST--
[profiling] Allocation profiling should be disabled when JIT is active on PHP 8.4
--DESCRIPTION--
We did find a crash in PHP when collecting a stack sample in allocation
profiling when JIT is activated while a function becomes hot. For the time being
we make sure to disable allocation profiling when we detect the JIT is enabled.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400)
    echo "skip: PHP Version < 8.4 are not affected", PHP_EOL;
if (PHP_VERSION_ID >= 80407)
    echo "skip: fixed since PHP version 8.4.7", PHP_EOL;
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
if (php_uname("s") === "Darwin")
    echo "skip: 'Darwin' has no JIT", PHP_EOL;
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
%aMemory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.4.7. See https://github.com/DataDog/dd-trace-php/pull/3199
%ADone.%a
