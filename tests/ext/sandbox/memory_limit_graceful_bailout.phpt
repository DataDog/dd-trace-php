--TEST--
The tracer bails out gracefully when memory_limit INI is reached in shutdown hook
--DESCRIPTION--
This test ensures that when the span stack is left in a "dirty" state from a zend_bailout() while serializing, they will be cleaned up properly in RSHUTDOWN.
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0') die('skip Zend memory manager required'); ?>
--ENV--
DD_TRACE_SPANS_LIMIT=-1
DD_TRACE_MEMORY_LIMIT=0
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--INI--
memory_limit=2M
max_execution_time=5
ddtrace.request_init_hook=
--FILE--
<?php
register_shutdown_function(function () {
    echo 'Flushing...' . PHP_EOL;
    if (PHP_VERSION_ID < 70000) {
        dd_trace_serialize_closed_spans();
        echo 'You should not see this.' . PHP_EOL;
    }
});

DDTrace\trace_function('array_sum', function (DDTrace\SpanData $span) {
    $span->name = 'array_sum' . str_repeat('.', 500);
    $span->resource = 'array_sum' . str_repeat('-', 500);
    $span->service = 'php';
    $span->type = 'web';
});

define('ALMOST_AT_MAX_MEMORY', 1024 * 1024 * 1.8); // 1.8M

// Added max_execution_time INI setting in case something goes wrong here
while (memory_get_usage() <= ALMOST_AT_MAX_MEMORY) {
    array_sum([]);
}

echo 'Done' . PHP_EOL;
?>
--EXPECTF--
Done
Flushing...

%sAllowed memory size of 2097152 bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
