--TEST--
Gracefully handle out-of-sync spans from traced function [internal]
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=dd_trace_serialize_closed_spans
--FILE--
<?php
// Since dd_trace_serialize_closed_spans() destroys the open span stack,
// when this closure runs, DDTrace\SpanData will have been freed already.
DDTrace\trace_function('dd_trace_serialize_closed_spans', function (DDTrace\SpanData $span) {
    echo 'You should not see this.' . PHP_EOL;
    $span->name = 'dd_trace_serialize_closed_spans';
});

var_dump(dd_trace_serialize_closed_spans());

echo 'Done.' . PHP_EOL;
?>
--EXPECT--
[ddtrace] [error] Cannot run tracing closure for dd_trace_serialize_closed_spans(); spans out of sync; This message is only displayed once. Specify DD_TRACE_ONCE_LOGS=0 to show all messages.
array(0) {
}
Done.
[ddtrace] [info] No finished traces to be sent to the agent
