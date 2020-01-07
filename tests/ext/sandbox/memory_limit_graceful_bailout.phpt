--TEST--
The tracer bails out gracefully when memory_limit INI is reached in shutdown hook
--DESCRIPTION--
This test ensures that when the span stack is left in a "dirty" state from a zend_bailout() while serializing, they will be cleaned up properly in RSHUTDOWN.
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0') die('skip Zend memory manager required'); ?>
--INI--
memory_limit=2M
--FILE--
<?php
register_shutdown_function(function () {
    dd_trace_serialize_closed_spans();
    echo 'You should not see this.' . PHP_EOL;
});

dd_trace_function('array_sum', function (DDTrace\SpanData $span) {
    $span->name = 'array_sum' . str_repeat('.', 500);
    $span->resource = 'array_sum' . str_repeat('-', 500);
    $span->service = 'php';
    $span->type = 'web';
});

for ($i = 0; $i < 900; $i++) {
    array_sum([]);
}

echo 'Done' . PHP_EOL;
?>
--EXPECTF--
Done

PHP Fatal error:  Allowed memory size of 2097152 bytes exhausted %s (tried to allocate %d bytes) in %s on line %d
