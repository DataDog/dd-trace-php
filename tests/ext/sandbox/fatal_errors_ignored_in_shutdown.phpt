--TEST--
Fatal errors are ignored in shutdown handler
--DESCRIPTION--
This is how the tracer sandboxes the flushing functionality in userland
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0') die('skip Zend memory manager required'); ?>
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--INI--
memory_limit=2M
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--FILE--
<?php
function flushTracer() {
    // Flushing happens in sandboxed tracing closure after the call.
    // Return a value from runtime to prevent Opcache from skipping the call.
    return mt_rand();
}

register_shutdown_function(function () {
    // Fatal errors will cause the same behavior as an "exit" (zend_bailout)
    // which prevents the rest of the shutdown handlers from being run.
    // We register the shutdown handler during shutdown to ensure this one is
    // the last one to run.
    // This will ensure that:
    // 1) Code in shutdown hooks can be instrumented
    // 2) If a fatal error occurs during flush, it will not affect the user's shutdown hooks
    register_shutdown_function(function () {
        // We wrap the call in a closure to prevent Opcache from skipping the call.
        flushTracer();
    });
});

register_shutdown_function(function () {
    echo 'Some user\'s shutdown' . PHP_EOL;
    var_dump(array_sum([10, 10, 10]));
    var_dump(error_get_last());
});

DDTrace\trace_function('flushTracer', function () {
    echo 'Flushing...' . PHP_EOL;
    array_map(function($span) {
        echo $span['name'] . PHP_EOL;
    }, dd_trace_serialize_closed_spans());
    // Trigger a fatal error (hit the memory limit)
    $a = str_repeat('.', 1024 * 1024 * 3); // 3MB
    echo 'You should not see this.' . PHP_EOL;
    var_dump(error_get_last());
});

DDTrace\trace_function('array_sum', function (DDTrace\SpanData $span) {
    $span->name = 'array_sum';
});

var_dump(array_sum([1, 2, 3]));
var_dump(array_sum([4, 5, 6]));
var_dump(array_sum([7, 8, 9]));
?>
--EXPECT--
int(6)
int(15)
int(24)
Some user's shutdown
int(30)
NULL
Flushing...
array_sum
array_sum
array_sum
array_sum
No finished traces to be sent to the agent
