--TEST--
test caching that calls are not traced works with opcache's protect_memory
--INI--
opcache.enable_cli=1
opcache.protect_memory=1
--FILE--
<?php

if (!extension_loaded('Zend OPcache')) die("opcache is required for this test\n");

require __DIR__ . '/include.php';

var_dump(opcache_is_script_cached(__DIR__ . '/include.php'));

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
bool(true)
Done.
