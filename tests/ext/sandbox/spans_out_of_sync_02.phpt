--TEST--
Gracefully handle out-of-sync spans from traced function [internal][default properties]
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=dd_trace_serialize_closed_spans
--FILE--
<?php
// Since dd_trace_serialize_closed_spans() destroys the open span stack,
// when this closure runs, DDTrace\SpanData will have been freed already.
DDTrace\trace_function('dd_trace_serialize_closed_spans', function (DDTrace\SpanData $span) {
    echo 'You should not see this.' . PHP_EOL;
});

var_dump(dd_trace_serialize_closed_spans());

echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Cannot run tracing closure for dd_trace_serialize_closed_spans(); spans out of sync
array(0) {
}
Done.
