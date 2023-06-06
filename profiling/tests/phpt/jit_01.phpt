--TEST--
Allocation profiling should be disabled when JIT is active
--DESCRIPTION--
We did find a crash in PHP when collecting a stack sample in allocation
profiling when JIT is activated in a `ZEND_GENERATOR_RETURN`. For the time being
we make sure to disable allocation profiling when we detect the JIT is enabled.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000)
    echo "skip: no JIT before PHP 8.0", PHP_EOL;
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
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
--EXPECTREGEX--
.*Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT. See https:\/\/github.com\/DataDog\/dd-trace-php\/pull\/2088
.*Done.
.*
