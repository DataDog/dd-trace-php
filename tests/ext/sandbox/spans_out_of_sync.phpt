--TEST--
Gracefully handle out-of-sync spans
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
ddtrace.traced_internal_functions=dd_trace_serialize_closed_spans
--FILE--
<?php
// Since dd_trace_serialize_closed_spans() destroys the open span stack,
// when this closure runs, DDTrace\SpanData will have been freed already.
dd_trace_function('dd_trace_serialize_closed_spans', function (DDTrace\SpanData $span) {
    echo 'You should not see this.' . PHP_EOL;
    $span->name = 'dd_trace_serialize_closed_spans';
});

var_dump(dd_trace_serialize_closed_spans());

echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Cannot run tracing closure for dd_trace_serialize_closed_spans(); spans out of sync
array(0) {
}
Done.
