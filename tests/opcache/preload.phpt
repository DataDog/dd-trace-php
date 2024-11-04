--TEST--
test caching that calls are not traced at first works with opcache
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
\DDTrace\trace_method('datadog\\negativeclass', 'negativemethod', function () {
    echo "NegativeClass::negative_method\n";
});
\DDTrace\trace_function('datadog\\negative_function', function () {
    echo "negative_function\n";
});

// call again
Datadog\NegativeClass::negativeMethod();
Datadog\negative_function();

echo "Done.";
?>
--EXPECT--
Executed negativeMethod
Executed negative_function
Executed negativeMethod
NegativeClass::negative_method
Executed negative_function
negative_function
Done.
