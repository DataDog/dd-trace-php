--TEST--
test caching that calls are not traced works with opcache
--INI--
opcache.enable_cli=1
opcache.preload={PWD}/include.php
--SKIPIF--
<?php
if (!version_compare(PHP_VERSION, '7.4.0', '>='))
    die('skip: test requires preloading, which requires PHP 7.4+');
?>
--FILE--
<?php
if (!extension_loaded('Zend OPcache')) die("opcache is required for this test\n");

// call the functions without tracing them to prime the cache
Datadog\NegativeClass::negativeMethod();
Datadog\negative_function();

// Add instrumentation calls (that will not work)
dd_trace_method('datadog\\negativeclass', 'negativemethod', function () {
    echo "NegativeClass::negative_method\n";
});
dd_trace_function('datadog\\negative_function', function () {
    echo "negative_function\n";
});

// call again (should not be traced)
Datadog\NegativeClass::negativeMethod();
Datadog\negative_function();

echo "Done.";
?>
--EXPECT--
Done.
