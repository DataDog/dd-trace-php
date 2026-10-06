--TEST--
[profiling] JIT is detected with the built-in OPcache on PHP 8.5+
--DESCRIPTION--
PHP 8.5 builds OPcache into the binary, so its zend_extension has no handle.
The JIT detection must not rely on that handle.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80500)
    echo "skip: OPcache is built in since PHP 8.5", PHP_EOL;
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_LOG_LEVEL=off
DD_PROFILING_ALLOCATION_ENABLED=no
--INI--
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=4M
--FILE--
<?php
ob_start();
(new ReflectionExtension('datadog-profiling'))->info();
$output = ob_get_clean();

preg_match('/^Allocation Profiling Enabled => (.*)$/m', $output, $matches);
echo $matches[1], PHP_EOL;
?>
--EXPECT--
Not available due to JIT being active, see https://github.com/DataDog/dd-trace-php/pull/3199 for more information.
