--TEST--
Gracefully handle out-of-sync spans in closure itself [user]
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php

DDTrace\trace_function('shutdown_and_flush', function (DDTrace\SpanData $span) {
    $span->name = 'shutdown_and_flush';

    /* This frees the struct holding $span; ensure we don't segfault as this is
     * akin to what we actually do in real scenarios.
     */
    dd_trace_serialize_closed_spans();
});

function shutdown_and_flush() {}
shutdown_and_flush();

echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Done.
