--TEST--
[profiling] phpinfo does not blame JIT for disabled allocation profiling on PHP 8.5+
--DESCRIPTION--
With allocation profiling turned off by the user and JIT active, phpinfo must
report `false`, not the JIT workaround of older PHP versions.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80500)
    echo "skip: OPcache is built in since PHP 8.5", PHP_EOL;
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
if (ini_get('opcache.jit') === false)
    echo "skip: PHP built without JIT", PHP_EOL;
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
false
