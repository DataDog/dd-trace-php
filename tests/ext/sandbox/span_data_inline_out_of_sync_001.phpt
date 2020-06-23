--TEST--
Inline API out-of-sync error with DDTrace\trace_function
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
use DDTrace\SpanData;

function bar($message) {
    $span = new SpanData();
    $span->name = 'userland';
    echo "bar($message)\n";
    // Not closing inline span to make span stack out of sync
}

DDTrace\trace_function('bar', function (SpanData $span) {
    $span->service = 'closure';
});

bar('hello');

var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECT--
bar(hello)
Cannot run tracing closure for bar(); spans out of sync
array(0) {
}
